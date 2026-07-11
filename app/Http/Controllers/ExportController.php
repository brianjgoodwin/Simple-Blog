<?php

namespace App\Http\Controllers;

use App\Support\PostExporter;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    /**
     * Download everything the logged-in author has written as a zip of
     * Markdown files. Scoped by Auth::user() alone — the request carries
     * no parameters, so there is nothing to tamper with.
     */
    public function __invoke(PostExporter $exporter): BinaryFileResponse
    {
        $author = Auth::user();

        $filename = $author->username.'-export-'.now()->toDateString().'.zip';

        return response()
            ->download($exporter->export($author), $filename, [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend();
    }
}
