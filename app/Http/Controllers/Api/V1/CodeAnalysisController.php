<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CodeAnalysisService;
use App\Repositories\SubmissionRepository;

class CodeAnalysisController extends Controller
{
    public function __construct(
        protected CodeAnalysisService $service,
        protected SubmissionRepository $repository
    ) {}

    public function analyze(Request $request)
    {
        $data = $request->validate([
            'language' => 'required|string',
            'code' => 'required|string'
        ]);

        $submission = $this->repository->create($data);

        $result = $this->service->analyze(
            $data['language'],
            $data['code']
        );

        $this->repository->update($submission, [
            'score' => $result->score,
            'meta' => json_encode($result->issues)
        ]);

        return response()->json([
            'id' => $submission->id,
            ...$result->toArray()
        ]);
    }
}