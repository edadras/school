<?php

namespace App\Modules\Files\Http;

use App\Http\Controllers\Controller;
use App\Models\StoredFile;
use App\Modules\Audit\Audit;
use App\Modules\Files\FileAccess;
use App\Modules\Files\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /** Upload to private storage. The file stays private to the uploader until attached to something. */
    public function store(Request $request, FileService $files): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);
        $f = $files->store($request->file('file'), $request->user());

        return response()->json(['data' => $f->only(['id', 'original_name', 'mime', 'size'])], 201);
    }

    /** Authorised callers get a short-lived signed URL (works for <img>, audio, video, download). */
    public function link(Request $request, FileAccess $access, FileService $files, int $id): JsonResponse
    {
        $f = StoredFile::findOrFail($id);
        abort_unless($access->canRead($request->user(), $f), 404); // 404: don't confirm existence

        return response()->json(['url' => $files->signedUrl($f), 'expires_in' => config('files.signed_url_minutes') * 60, 'name' => $f->original_name, 'mime' => $f->mime]);
    }

    /** Signature-protected, no session. Registered outside the auth group (see routes/api.php). */
    public function download(Request $request, int $id): StreamedResponse
    {
        $f = StoredFile::withoutGlobalScopes()->findOrFail($id);
        $disk = Storage::disk($f->disk);
        abort_unless($disk->exists($f->path), 404);

        $inline = in_array($f->mime, config('files.inline_mimes'), true);

        return $disk->response($f->path, $f->original_name, [
            'Content-Type' => $f->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=60',
        ], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $f = StoredFile::where('user_id', $request->user()->id)->whereNull('context_type')->findOrFail($id);
        Storage::disk($f->disk)->delete($f->path);
        $f->delete();

        return response()->json(null, 204);
    }
}
