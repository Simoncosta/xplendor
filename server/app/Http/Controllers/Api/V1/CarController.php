<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiPaginate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarRequest;
use App\Http\Requests\PaginateRequest;
use App\Models\Car;
use App\Services\CarAiAnalysesService;
use App\Services\CarService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Throwable;

class CarController extends Controller
{
    public function __construct(
        protected CarService $carService,
        protected CarAiAnalysesService $carAiAnalysesService
    ) {}

    public function index(PaginateRequest $request, int $companyId)
    {
        $user = Auth::user();

        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $filter = $user->role === 'root' ? [] : ['company_id' => $user->company_id];

        if ($request->input('status')) {
            $filter['status'] = $request->input('status');
        }

        if ($request->has('is_resume') && $request->input('is_resume') !== '') {
            $filter['is_resume'] = $request->boolean('is_resume');
        }

        if ($request->filled('car_brand_id')) {
            $filter['car_brand_id'] = explode(',', $request->input('car_brand_id'));
        }

        if ($request->filled('car_model_id')) {
            $filter['car_model_id'] = explode(',', $request->input('car_model_id'));
        }

        if ($request->filled('mincost') && $request->filled('maxcost')) {
            $filter['price_gross'] = ['between' => [$request->input('mincost'), $request->input('maxcost')]];
        }

        $orderBy = $request->filled('sort_by')
            ? [$request->input('sort_by') => $request->input('sort_direction')]
            : [];

        $paginate = $request->input('perPage')
            ? ApiPaginate::perPage($request)
            : null;

        $cars = $this->carService->getAll(
            ['*'],
            ['images', 'externalImages', 'car360ExteriorImages', 'brand', 'model', 'views:id,car_id', 'leads:id,car_id', 'interactions:id,car_id', 'company:id,fiscal_name'],
            $paginate,
            $filter,
            $orderBy
        );

        return ApiResponse::success($cars, 'Cars fetched successfully.');
    }

    public function store(CarRequest $request, int $companyId)
    {
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validated();
        $data['company_id'] = $companyId;

        $car = $this->carService->store($data);

        return ApiResponse::success($car, 'Car created successfully.');
    }

    public function show(int $companyId, int $id)
    {
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $car = $this->carService->findOrFail(
            $id,
            'id',
            ['*'],
            ['images', 'externalImages', 'brand', 'model', 'car360ExteriorImages', 'views', 'leads', 'analyses']
        );

        return ApiResponse::success($car, 'Car fetched successfully.');
    }

    public function downloadImages(int $companyId, int $carId)
    {
        $user = Auth::user();

        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        try {
            $archive = $this->carService->buildImagesDownloadArchive($companyId, $carId);

            return response()->download($archive['path'], $archive['filename'])->deleteFileAfterSend(true);
        } catch (\DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return ApiResponse::error('Não foi possível gerar o ficheiro ZIP.', 500);
        }
    }

    public function update(CarRequest $request, int $companyId, int $id)
    {
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validated();
        $data['company_id'] = $companyId;

        $car = $this->carService->update($id, $data);

        return ApiResponse::success($car, 'Car updated successfully.');
    }

    public function destroy(int $companyId, int $id)
    {
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $this->carService->destroy($id);

        return ApiResponse::success(null, 'Car deleted successfully.');
    }

    public function generateAiAnalyses(int $companyId, int $carId)
    {
        try {
            $car = $this->carService->findOrFail(
                $carId,
                'id',
                ['*'],
                ['brand', 'model', 'views', 'leads', 'interactions']
            );
            $car['company_id'] = $companyId;
            $car['car_id'] = $carId;

            $analyses = $this->carService->generateAiAnalyses($car);

            return ApiResponse::success($analyses, 'Analyses generated successfully.');
        } catch (\RuntimeException $exception) {
            return ApiResponse::error($exception->getMessage(), 503);
        } catch (Throwable $exception) {
            return ApiResponse::error('Nao foi possivel gerar a analise IA desta viatura.', 500);
        }
    }

    public function feedbackAiAnalyses(Request $request, int $companyId, int $carAiAnalysisId)
    {
        $data = $request->validate([
            'feedback' => 'required|string|max:255|in:positive,negative',
        ]);

        $this->carAiAnalysesService->update($carAiAnalysisId, $data);
        $analysis = $this->carAiAnalysesService->findOrFail($carAiAnalysisId, 'id', ['*']);

        return ApiResponse::success($analysis, 'Feedback saved successfully.');
    }
}
