<?php

namespace App\Console\Commands;

use Goutte\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\BrowserKit\CookieJar;
use Symfony\Component\BrowserKit\Cookie;
use Intervention\Image\Facades\Image;

class Scrape extends Command
{
    /**
     * @var int
     */
    protected $tries = 0;

    /**
     * @var int
     */
    protected $success = 0;

    /**
     * @var int
     */
    protected $failure = 0;

    /**
     * @var string
     */
    protected $storeDestination;

    /**
     * @var Client
     */
    protected $client;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape
                            {--id= : The illust id <required>}
                            {--start-page= : First page to start}
                            {--end-page= : Last page}
                            {--sort= : Sort images by, default "image_view", accept: "image_created", "image_view"}
                            {--session= : User session cookie value for authentication}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape images from http://seiga.nicovideo.jp/';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (! $this->checkOptions()) {
            return;
        }

        $this->setClient();
        $this->storeDestination = 'images/' . time();
        $startPage = $this->option('start-page') ?: 1;
        $endPage = $this->option('end-page') ?: 1;
        $sort = $this->option('sort') ?: 'image_view';
        $this->info('Link start!');

        for ($page = $startPage; $page <= $endPage; $page++) {
            $imagesCount = 0;
            $currentPage = $page > 0 ? $page : 1;
            $reaminPage = $endPage - $currentPage;
            $this->line('Start with page ' . $currentPage . ', remain ' . $reaminPage);
            $url = 'http://seiga.nicovideo.jp/user/illust/'. $this->option('id') .'?sort=' . $sort . '&page=' . $currentPage;
            $previewLinks = $this->client->request('GET', $url)
                ->filter('.list_item.no_trim a')
                ->links();
            $this->info('Found ' . count($previewLinks) . ' images on the page');

            foreach ($previewLinks as $link) {
                $failure = $this->failure;
                $this->info('---------------------------------------------------------------------');
                $this->line('Saving image ' . ++$imagesCount . '/' . count($previewLinks) . ' of page ' . $currentPage . ' reamin ' . $reaminPage . ' page');
                preg_match('/^http:\/\/seiga\.nicovideo\.jp\/seiga\/im(?<id>[0-9]+)$/', $link->getUri(), $matches);
                $this->saveImage($matches['id']);

                if ($failure === $this->failure) {
                    $this->info('Image saved!');
                }
            }
        }
        $this->info('Job finished with ' . $this->success . '/' . $this->tries . ' success and ' . $this->failure . ' failure');
        $this->info($this->success . ' images saved in ' . storage_path($this->storeDestination) . '/');
    }

    /**
     * Check required options.
     *
     * @return bool
     */
    protected function checkOptions()
    {
        if (! $this->option('id')) {
            $this->error('Option "id" is requred!');
            return false;
        }

        return true;
    }

    /**
     * Set crawler client.
     */
    protected function setClient()
    {
        $cookieName = 'user_session';
        $cookieVaule = $this->option('session') ?: 'user_session_42610806_8aa08589a68890008b6cfcf316acbfb61830353c794fef15aeaf3d9ae4a14b00';
        $cookie = new Cookie($cookieName, $cookieVaule, null, null, '.nicovideo.jp');
        $cookieJar = new CookieJar;
        $cookieJar->set($cookie);
        $this->client = new Client([], null, $cookieJar);
    }

    /**
     * Save image to it's destination.
     *
     * @param $id
     */
    protected function saveImage($id)
    {
        $imgHost = 'https://lohas.nicoseiga.jp';
        $imgUri = $this->client
            ->request('GET', 'http://seiga.nicovideo.jp/image/source/' . $id)
            ->filter('.illust_view_big')
            ->attr('data-src');

        try {
            $this->tries++;
            $this->info('Start with image ID: ' . $id);
            if (! Storage::exists($this->storeDestination)) {
                Storage::makeDirectory($this->storeDestination);
                $this->info('Generate folder ' . storage_path($this->storeDestination) . '/');
            }
            Image::make($imgHost . '/' . $imgUri)->save(storage_path('app/' . $this->storeDestination . '/' . time() . '.jpg'));
            $this->success++;
        } catch (\Exception $e) {
            $this->error('Failure occurred! Exception: ' . get_class($e));
            $this->failure++;
        }
    }
}
