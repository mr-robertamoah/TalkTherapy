<?php

namespace App\Services;

use App\DTOs\FileUploadDTO;
use App\Models\Counsellor;
use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FileService extends Service
{
    public function uploadFile(FileUploadDTO $fileUploadDTO)
    {
        if (is_null($fileUploadDTO->file)) return null;

        $disk = env('FILESYSTEM_DISK', $fileUploadDTO->disk);
        
        $data = [];
        $data['mime'] = $fileUploadDTO->file->getClientMimeType();
        $data['size'] = $fileUploadDTO->file->getSize();
        $data['storage'] = $disk;
        $data['path'] = $fileUploadDTO->path;

        $result = Storage::disk($disk)->put($fileUploadDTO->path, $fileUploadDTO->file);

        $data['name'] = str_replace(strlen($data['path']) ? $data['path'] . '/' : '', '', $result);

        return $data;
    }

    private function getNameOfFile(UploadedFile $file)
    {
        return sha1($file->getClientOriginalName());
    }

    public function saveFile(User|Counsellor $addedby, array $data): File
    {
        $file = $addedby->addedFiles()->create($data);

        return $file;
    }

    public function deleteFile(File|null $file)
    {
        if (is_null($file)) return;

        $path = (strlen($file->path) ? $file->path . '/' : '') . $file->name;
        
        Storage::disk($file->storage)->delete($path);

        return $file->delete();
    }
}