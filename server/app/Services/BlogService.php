<?php

namespace App\Services;

use App\Models\Blog;
use App\Models\EditorialPost;
use App\Models\User;
use App\Repositories\Contracts\BlogRepositoryInterface;
use App\Support\BlogHtml;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Gravação dos artigos do blog: conteúdo limpo (lista de etiquetas permitidas), slug único
 * por empresa e fixo depois de publicado, tempo de leitura e banner (WebP). Os estados
 * (enviar, aprovar, publicar) vivem no BlogWorkflowService; aqui o estado nunca muda.
 */
class BlogService extends BaseService
{
    /** Campos de conteúdo que o formulário pode alterar. */
    private const CONTENT_FIELDS = [
        'title', 'subtitle', 'excerpt', 'content', 'tags', 'category',
        'meta_title', 'meta_description', 'focus_keyword', 'seo_answer_first_ok',
    ];

    public function __construct(protected BlogRepositoryInterface $blogRepository)
    {
        parent::__construct($blogRepository);
    }

    /** Cria um artigo, sempre em rascunho. */
    public function createArticle(int $companyId, User $author, array $data): Blog
    {
        $blog = DB::transaction(function () use ($companyId, $author, $data) {
            // Ponte da Linha Editorial: a publicação tem de ser da empresa, do canal "Site" e
            // ainda sem artigo (bloqueada para não ligar dois artigos ao mesmo tempo).
            $post = null;
            if (! empty($data['editorial_post_id'])) {
                $post = EditorialPost::where('company_id', $companyId)->lockForUpdate()->find((int) $data['editorial_post_id']);
                if (! $post || $post->channel !== 'site') {
                    throw ValidationException::withMessages(['editorial_post_id' => ['Publicação do canal Site não encontrada.']]);
                }
                if ($post->blog_id && Blog::whereKey($post->blog_id)->exists()) {
                    throw ValidationException::withMessages(['editorial_post_id' => ['Esta publicação já tem um artigo ligado.']]);
                }
            }

            $blog = new Blog(['company_id' => $companyId, 'user_id' => $author->id, 'status' => Blog::DRAFT]);
            $this->fill($blog, $data);
            $blog->save();
            $post?->update(['blog_id' => $blog->id]);

            return $blog;
        });

        if (($data['banner'] ?? null) instanceof UploadedFile) {
            $this->replaceBanner($blog, $data['banner']);
        }

        return $blog->refresh();
    }

    /** Altera o conteúdo de um artigo (as permissões e o estado são verificados antes). */
    public function updateArticle(Blog $blog, array $data): Blog
    {
        DB::transaction(function () use ($blog, $data) {
            $this->fill($blog, $data);
            $blog->save();
        });

        if (($data['banner'] ?? null) instanceof UploadedFile) {
            $this->replaceBanner($blog, $data['banner']);
        }

        return $blog->refresh();
    }

    public function deleteArticle(Blog $blog): void
    {
        if ($blog->banner) {
            $this->deleteBanner($blog->banner);
        }
        $blog->delete();
    }

    public function removeBanner(Blog $blog): Blog
    {
        if ($blog->banner) {
            $this->deleteBanner($blog->banner);
        }
        $blog->forceFill(['banner' => null, 'og_image' => null])->save();

        return $blog->refresh();
    }

    // ── internos ─────────────────────────────────────────────────────────────

    private function fill(Blog $blog, array $data): void
    {
        foreach (self::CONTENT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $blog->{$field} = $data[$field];
            }
        }

        // Limpeza ao gravar: só a lista de etiquetas permitidas chega à base de dados.
        $blog->content = BlogHtml::sanitize($blog->content);
        foreach (['title', 'subtitle', 'excerpt', 'category', 'meta_title', 'meta_description', 'focus_keyword'] as $field) {
            $value = $blog->{$field};
            $blog->{$field} = is_string($value) && trim($value) !== '' ? trim(strip_tags($value)) : null;
        }
        $blog->tags = array_values(array_filter(array_map(fn ($t) => trim(strip_tags((string) $t)), (array) ($blog->tags ?? []))));
        $blog->read_time = BlogHtml::readTime($blog->content);

        // O Open Graph segue o SEO (calculado à saída quando vazio); deixa de haver cópias desatualizadas.
        $blog->og_title = null;
        $blog->og_description = null;

        $this->applySlug($blog, array_key_exists('slug', $data) ? (string) ($data['slug'] ?? '') : null);
    }

    /**
     * Slug único por empresa (incluindo artigos apagados, que ainda ocupam o endereço).
     * Escrito pelo utilizador: tem de estar livre (422). Vazio: vem do título, com sufixo
     * se preciso. Depois de publicado não muda (422 se vier diferente).
     */
    private function applySlug(Blog $blog, ?string $requested): void
    {
        $requested = $requested !== null ? Str::slug($requested) : null;

        if ($blog->exists && $blog->isSlugLocked()) {
            if ($requested !== null && $requested !== '' && $requested !== $blog->getOriginal('slug')) {
                throw ValidationException::withMessages(['slug' => ['O endereço (slug) não pode mudar depois de o artigo ser publicado.']]);
            }

            return;
        }

        if ($requested !== null && $requested !== '') {
            $requested = Str::limit($requested, 180, '');
            if ($this->slugTaken((int) $blog->company_id, $requested, $blog->id)) {
                throw ValidationException::withMessages(['slug' => ['Este endereço já é usado noutro artigo da empresa.']]);
            }
            $blog->slug = $requested;

            return;
        }

        if ($blog->exists && $requested === null && $blog->slug) {
            return; // não veio no pedido: mantém
        }

        $base = Str::limit(Str::slug((string) $blog->title), 180, '') ?: 'artigo';
        $slug = $base;
        for ($i = 2; $this->slugTaken((int) $blog->company_id, $slug, $blog->id); $i++) {
            $slug = "{$base}-{$i}";
        }
        $blog->slug = $slug;
    }

    private function slugTaken(int $companyId, string $slug, ?int $ignoreId): bool
    {
        return Blog::withTrashed()
            ->where('company_id', $companyId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    private function replaceBanner(Blog $blog, UploadedFile $image): void
    {
        $folder = "company_{$blog->company_id}/blogs";
        Storage::disk('public')->makeDirectory($folder);

        // Nome novo a cada envio: evita imagens antigas em cache nos sites e nas redes.
        $path = "{$folder}/{$blog->slug}-" . Str::lower(Str::random(6)) . '.webp';
        $converted = (new ImageManager(new Driver()))->read($image)->scaleDown(width: 1600)->toWebp(85)->toString();
        Storage::disk('public')->put($path, $converted);

        if ($blog->banner) {
            $this->deleteBanner($blog->banner);
        }

        $url = Storage::url($path);
        $blog->forceFill(['banner' => $url, 'og_image' => $url])->save();
    }

    private function deleteBanner(string $path): void
    {
        Storage::disk('public')->delete(str_replace('/storage/', '', $path));
    }
}
