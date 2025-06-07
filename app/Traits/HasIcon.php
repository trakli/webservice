<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

trait HasIcon
{
    private function updateIcon(Model $model, array $data, Request $request)
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
                $path = $request->file('icon')->store('categories');
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
