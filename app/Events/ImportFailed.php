<?php

namespace App\Events;

use App\Models\FileImport;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ImportFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    private FileImport $fileImport;

    /**
     * Create a new event instance.
     */
    public function __construct(FileImport $fileImport)
    {
        $this->fileImport = $fileImport;
    }
}
