<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class FileService
{
    public const ALLOWED_EXTENSIONS = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv';

    public const MAX_KILOBYTES = 5120;

    public static function uploadFiles(Model $model, Request $request, string $key, string $folder, ?string $documentType = null)
    {
        if ($request->hasFile($key)) {
            foreach ($request->file($key) as $file) {
                $path = $file->store($folder);
                $model->files()->create(array_filter([
                    'path' => $path,
                    'type' => self::detectType($file),
                    'metadata' => $documentType ? ['document_type' => $documentType] : null,
                ], fn ($value) => $value !== null));
            }
        }
    }

    private static function detectType(UploadedFile $file): string
    {
        $mime = $file->getMimeType() ?? '';

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if ($mime === 'application/pdf') {
            return 'pdf';
        }

        return 'document';
    }

    public static function updateIcon(Model $model, array $data, Request $request, string $folder = 'icons')
    {
        // file upload
        if ($request->has('icon')) {
            $icon = $model->icon;
            if (is_null($icon)) {
                $icon = $model->icon()->create([
                    'type' => $data['icon_type'],
                ]);
            }

            if ($request->hasFile('icon')) {
                Validator::validate($request->all(), [
                    'icon' => 'file|mimes:png,jpg,jpeg|max:1024',
                ]);

                $type = $request->file('icon')->getClientOriginalExtension();
                $path = $request->file('icon')->store($folder);
                $current_image = $icon->image;
                if (is_null($current_image)) {
                    $icon->image()->create([
                        'path' => $path,
                        'type' => $type,
                    ]);
                } else {
                    $icon->image()->update([
                        'path' => $path,
                    ]);
                }
            } else {
                $icon->update(['content' => $data['icon']]);
            }
        }
    }
}
