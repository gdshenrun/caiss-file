<?php


namespace GdShenrun\Caiss\File;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class UploadServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(Upload::class);
    }
}
