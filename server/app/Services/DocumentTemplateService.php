<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Resources\SaleDocumentDataResource;
use App\Models\Car;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\Municipality;
use App\Repositories\Contracts\DocumentTemplateRepositoryInterface;
use App\Support\DocumentVariables;
use App\Support\DocxTemplateFiller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class DocumentTemplateService extends BaseService
{
    public function __construct(protected DocumentTemplateRepositoryInterface $documentTemplateRepository)
    {
        parent::__construct($documentTemplateRepository);
    }

    /** Guarda o .docx carregado (disco 'local', isolado por empresa) + cria o registo. */
    public function storeUpload(int $companyId, string $name, UploadedFile $file): DocumentTemplate
    {
        $folder = "company_{$companyId}/document_templates";
        $path = $file->store($folder, 'local');

        return $this->documentTemplateRepository->store([
            'company_id'    => $companyId,
            'name'          => $name,
            'original_path' => $path,
            'archived'      => false,
        ]);
    }

    /**
     * Substitui o .docx do modelo: guarda o novo, aponta o registo para ele e
     * APAGA o ficheiro antigo do storage (sem versionamento, sem órfãos). O
     * modelo mantém id/nome; as gerações passam a usar o novo ficheiro.
     */
    public function replaceFile(DocumentTemplate $template, UploadedFile $file): DocumentTemplate
    {
        $old = $template->original_path;

        $folder = "company_{$template->company_id}/document_templates";
        $newPath = $file->store($folder, 'local');

        $template->update(['original_path' => $newPath]);

        // Apaga o antigo só depois de o novo estar gravado e apontado.
        if ($old && $old !== $newPath && Storage::disk('local')->exists($old)) {
            Storage::disk('local')->delete($old);
        }

        return $template->fresh();
    }

    /** Elimina o registo E o ficheiro do storage (não há dependentes — os
     *  documentos gerados são descarregados, não guardados). */
    public function deleteWithFile(DocumentTemplate $template): void
    {
        if ($template->original_path && Storage::disk('local')->exists($template->original_path)) {
            Storage::disk('local')->delete($template->original_path);
        }
        $this->documentTemplateRepository->destroy($template->id);
    }

    /**
     * Gera o documento a partir do modelo + dados da venda.
     *
     * @param array<string,string> $extra  valores EFÉMEROS (do preview) — não gravam.
     * @param bool $preview  true → lacunas ficam como {{var}} literais (para o
     *                       frontend as assinalar/preencher em tempo real);
     *                       false → download final (lacunas válidas vazias ficam
     *                       em branco; não reconhecidas ficam {{var}} literais).
     *
     * @return array{path: string, unknown: array<int,string>, filename: string}
     */
    public function generate(DocumentTemplate $template, int $companyId, int $carId, array $extra = [], bool $preview = false): array
    {
        $srcAbs = Storage::disk('local')->path($template->original_path);
        if (! is_file($srcAbs)) {
            throw new \RuntimeException('Ficheiro do modelo não encontrado.');
        }

        $saco       = $this->buildSaco($companyId, $carId);
        $catalog    = DocumentVariables::resolveFromData($saco); // key => value ("" se vazio)
        $catalogKeys = array_keys($catalog);

        $resolve = function (string $var) use ($catalog, $catalogKeys, $extra, $preview): string {
            // Valor efémero introduzido no preview tem prioridade.
            $typed = isset($extra[$var]) ? trim((string) $extra[$var]) : '';
            if ($typed !== '') {
                return $typed;
            }
            $inCatalog = in_array($var, $catalogKeys, true);

            if ($preview) {
                // Preenche as que têm dados; deixa lacunas (válidas-vazias E não
                // reconhecidas) como {{var}} para o frontend as tratar.
                return ($inCatalog && $catalog[$var] !== '') ? $catalog[$var] : '{{' . $var . '}}';
            }
            // Download: válida → valor (ou "" se vazia); não reconhecida → literal.
            return $inCatalog ? $catalog[$var] : '{{' . $var . '}}';
        };

        Storage::disk('local')->makeDirectory('tmp');
        $outAbs = Storage::disk('local')->path('tmp/doc_' . Str::random(24) . '.docx');

        $result  = DocxTemplateFiller::render($srcAbs, $outAbs, $resolve);
        $unknown = array_values(array_diff($result['found'], $catalogKeys));

        $plate = $saco['car']['license_plate'] ?? null;
        $filename = Str::slug($template->name) . ($plate ? '-' . Str::slug((string) $plate) : '') . '.docx';

        return ['path' => $outAbs, 'unknown' => $unknown, 'filename' => $filename];
    }

    /** .docx de exemplo (mostra o formato {{ }} ao stand). */
    public function exampleDocx(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Modelo de documento — exemplo', ['bold' => true, 'size' => 14]);
        $section->addTextBreak();
        $section->addText('Escreva o texto do seu documento normalmente e insira variáveis entre chavetas duplas. Ao gerar numa venda, o XPLENDOR substitui-as pelos dados reais.');
        $section->addTextBreak();
        $section->addText('Exemplo:');
        $section->addText('Eu, {{cliente_nome}}, NIF {{cliente_nif}}, declaro ter adquirido a viatura {{viatura_marca}} {{viatura_modelo}}, matrícula {{viatura_matricula}}, a {{empresa_nome}} ({{empresa_localidade}}), em {{venda_data}}.');
        $section->addTextBreak();
        $section->addText('Consulte a lista completa de variáveis disponíveis na área de Modelos de documento.', ['italic' => true]);

        Storage::disk('local')->makeDirectory('tmp');
        $out = Storage::disk('local')->path('tmp/exemplo_modelo_' . Str::random(12) . '.docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($out);

        return $out;
    }

    /** Monta o "saco" (empresa + viatura + cliente + venda) no shape do Resource. */
    private function buildSaco(int $companyId, int $carId): array
    {
        $car = Car::with(['brand', 'model', 'sale.customer.municipality'])
            ->where('company_id', $companyId)
            ->find($carId);

        $company = Company::find($companyId);
        $companyLocality = $company && $company->municipality_id
            ? Municipality::find($company->municipality_id)?->name
            : null;

        return (new SaleDocumentDataResource([
            'company'          => $company,
            'company_locality' => $companyLocality,
            'car'              => $car,
            'customer'         => $car?->sale?->customer,
            'sale'             => $car?->sale,
        ]))->resolve();
    }
}
