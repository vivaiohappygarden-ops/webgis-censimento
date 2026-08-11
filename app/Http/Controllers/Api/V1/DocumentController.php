<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:assets.view', only: ['file'])];
    }

    public function file(string $id): StreamedResponse|Response
    {
        $document = Document::findOrFail($id);
        $disk = Storage::disk();

        abort_unless($disk->exists($document->s3_key), 404);

        return $disk->response($document->s3_key, $document->title, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
