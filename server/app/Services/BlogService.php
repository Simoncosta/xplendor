<?php

namespace App\Services;

use App\Repositories\Contracts\BlogRepositoryInterface;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class BlogService extends BaseService
{
    public function __construct(protected BlogRepositoryInterface $blogRepository)
    {
        parent::__construct($blogRepository);
    }

    public function store(array $data): mixed
    {
        $blog = $this->blogRepository->store($data);

        // Processar o banner
        if (!empty($data['banner']) && $data['banner'] instanceof UploadedFile) {
            $bannerPath = $this->handleBannerUpload($data['banner'], $data['company_id'], $blog->slug);

            // Atualiza o campo 'banner'
            $blog->banner = $bannerPath;

            // Se o og_image não foi enviado, define o banner como imagem OG
            if (empty($data['og_image']) && empty($blog->og_image)) {
                $blog->og_image = $bannerPath;
            }

            $blog->save();
        }

        return $blog;
    }

    public function update(int $id, array $data): mixed
    {
        $blog = $this->blogRepository->findOrFail($id, 'id');
        $slug = $blog->slug;

        // Atualizar dados principais
        $this->blogRepository->update($id, $data);

        // Substituir o banner se houver novo envio
        if (!empty($data['banner']) && $data['banner'] instanceof UploadedFile) {
            // Deletar o anterior
            if ($blog->banner) {
                $this->deleteBanner($blog->banner);
            }

            // Salvar novo banner
            $bannerPath = $this->handleBannerUpload($data['banner'], $data['company_id'], $blog->id, $slug);
            $blog->banner = $bannerPath;
            $blog->save();
        }

        return $blog->refresh();
    }

    public function destroy(int $id): bool
    {
        $blog = $this->blogRepository->findOrFail($id, 'id');

        // Deletar o banner do storage, se existir
        if ($blog->banner) {
            $this->deleteBanner($blog->banner);
        }

        // Remover o registro do banco
        return $this->blogRepository->destroy($id);
    }

    private function deleteBanner(string $path): void
    {
        $relativePath = str_replace('/storage/', '', $path);
        Storage::disk('public')->delete($relativePath);
    }

    private function handleBannerUpload(UploadedFile $image, int $companyId, string $slug): string
    {
        $folder = "company_{$companyId}/blogs";
        Storage::disk('public')->makeDirectory($folder);

        $manager = new ImageManager(new Driver());

        // nome fixo, sem timestamp
        $filename = "{$slug}.webp";
        $path = "{$folder}/{$filename}";

        $converted = $manager->read($image)->toWebp(85)->toString();
        Storage::disk('public')->put($path, $converted);

        return Storage::url($path);
    }
}
