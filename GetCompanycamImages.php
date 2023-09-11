<?php

namespace App\Console\Commands;

use App\Client;
use App\CompanyCam;
use App\Image;
use App\Jobs\ImageUpload;
use App\Jobs\VideoUpload;
use App\Notifications\SlackMessage;
use App\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetCompanyCamImages extends Command
{

    const CC_GALLERY_IMG_CLASS_NAME = 'cc-grid-image';
    const CC_GALLERY_LOAD_MORE_TEXT = 'Load More';
    const CC_PAGE_URL = '.turbo_stream?page=';
    const DRIVE_IMG_SRC = 'https://lh3.googleusercontent.com/u/0/d/';
    const VIDEO_EXT = 'mp4';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_companycam_images';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
     * @return int
     */
    public function handle()
    {
        $companyCam = CompanyCam::where('status', 'in_progress')->first(); //for server load time)

        try {
            if (!$companyCam) {
                echo 'InProgress company cam not found' . PHP_EOL;
                return;
            }

            $client = new \Goutte\Client();
            $requestUrl = $companyCam->url;
            $links = [];
            $videoLinks = [];

            Log::info('GetCompanyCamImagesAndVideos => start', ['companyCam' => $companyCam]);
            if (str_contains($companyCam->url, 'app.companycam.com/galleries')) {
                $links = $this->loadImagesFromNextPages($requestUrl, $companyCam, $client);
            }

            if (str_contains($companyCam->url, 'albums.acculynx.com')) {
                $scrapUrl = $url = config('services.companycam.acculynx_scrap_url_by_event_id');
                $parsedUrl = parse_url($companyCam->url);

                $eventId = isset($parsedUrl['path']) ? substr($parsedUrl['path'], 1) : null;

                if (!$eventId) {
                    Log::info('GetCompanyCamImages => get acculynx event ID', ['companyCam' => $companyCam]);
                    return;
                }

                $url = $scrapUrl . "$eventId&companyTimeZoneID=Central+Standard+Time";
                $responseJson = Http::get($url);
                $resArr = json_decode($responseJson->getBody()->getContents(), true);

                foreach ($resArr['albumFiles'] as $data) {
                    $links[] = $data['fileUrl'];
                }

                $this->storeLinkImages($links, $companyCam);
            }

            if (str_contains($companyCam->url, 'app.companycam.com/timeline')) {
                $url = config('services.companycam.timeline_scrap_url');
                $responseJson = Http::get($url . "?url=" . $companyCam->url);
                $links = json_decode(json_decode($responseJson->getBody()->getContents(), true));
                $this->storeLinkImages($links, $companyCam);
            }

            if (str_contains($companyCam->url, 'drive.google')) {
                $crawler = $client->request('GET', $requestUrl);
                $crawler->filter('.WYuW0e')->each(function ($node) use (&$companyCam, &$links) {
                $imageBrowseUrl = self::DRIVE_IMG_SRC . $node->attr('data-id');
                     try {
                         $links[] = $imageBrowseUrl;
                     } catch (\Exception $error) {
                         Log::error($error->getMessage(), ['error' => $error]);
                     }
                });
                $this->storeLinkImages($links, $companyCam);
            }

            $companyCam->update(['status' => 'success']);
            Log::info('GetCompanyCamImages => links', ['links' => $links]);
        } catch (\Exception $exception) {
            Log::error('GetCompanyCamImages => error', ['exception' => $exception]);
            $companyCam->update(['status' => 'error']);
            $user = \App\User::first();
            $user->notify(new SlackMessage($exception));
        }
    }

    /**
     * @param $links
     * @param $companyCam
     * @return void
     */
    public function storeLinkVideos($links, $companyCam)
    {
        foreach ($links as $link) {
            if (!$link) {
                continue;
            }
            $videoFileName = str_contains($link, 'https://') && strpos($link, '?') === false
                ? basename($link)
                : basename(substr($link, 0, strpos($link, '?')));
            $ext = substr(strrchr($videoFileName, '.'), 1);

            $uuid = 'video_' . uniqid();
            $data['type'] = 'company_cam';
            $data['videoable_id'] = $companyCam->client_id;
            $data['videoable_type'] = Client::class;
            $data['title'] = $uuid;
            $data['uploaded_status'] = Video::IN_PROGRESS;
            $video = Video::updateOrCreate($data, $data);
            VideoUpload::dispatch([
                'videoId' => $video->id,
                'folderName' => 'roofs',
                'tempPath' => $link,
                'type' => $data['type'],
                'videoableId' => $data['videoable_id'],
                'uuid' => $uuid,
                'ext' => $ext,
                'repo' => 's3'
            ]);
        }
    }

    /**
     * Create InProgress images and start async upload (S3) process
     * @param array $links
     * @param CompanyCam $companyCam
     */
    public function storeLinkImages($links, $companyCam)
    {
        foreach ($links as $link) {
            if (!$link) {
                continue;
            }
            $imageFileName = str_contains($link, 'https://') && strpos($link, '?') === false
                ? basename($link)
                : basename(substr($link, 0, strpos($link, '?')));

            $ext = pathinfo($link, PATHINFO_EXTENSION);
            $ext = strtok($ext, '?');
            $uuid = uniqid();
            try {
                if (!$ext) {
                    $file_info = new \finfo(FILEINFO_MIME_TYPE);
                    $mime_type = $file_info->buffer(file_get_contents($link));
                    $ext = explode('/', $mime_type)[1];
                    $ext = $ext === 'heif' ? 'heic' : $ext;
                    $imageFileName = replaceExtension($imageFileName, $ext);
                }
            } catch (\Throwable $th) {
                Log::error('storeLinkImages', ['link' => $link]);
                continue;
            }

            $data['type'] = 'company_cam';
            $data['imageable_id'] = $companyCam->client_id;
            $data['imageable_type'] = Client::class;
            $data['title'] = $uuid;
            $data['uploaded_status'] = Image::IN_PROGRESS;
            $image = Image::updateOrCreate($data, $data);

            ImageUpload::dispatch([
                'imageId' => $image->id,
                'folderName' => 'roofs',
                'tempPath' => $link,
                'imageFileName' => "$uuid.$ext",
                'type' => $data['type'],
                'imageableId' => $data['imageable_id'],
                'repo' => 's3_image_upload'
            ]);
        }
    }

    /**
     * @param $crawler
     * @return array
     */
    public function getImgLinksFromContent($crawler)
    {
        $links = [];
        $crawler->filter('.' . self::CC_GALLERY_IMG_CLASS_NAME)->each(function ($node) use (&$links) {
            $links[] = $node->attr('data-full');
        });

        return $links;
    }

    /**
     * @param $crawler
     * @return array
     */
    public function getVideoLinksFromContent($crawler)
    {
        $videoLinks = [];
        $crawler->filter('source')->each(function ($node) use (&$videoLinks) {
            $videoLinks[] = $node->attr('src');
        });

        return $videoLinks;
    }

    public function loadImagesFromNextPages($companyCamUrl, $companyCam, $client)
    {
        $pageCount = 1;

        do {
            try {
                $url = $companyCamUrl . self::CC_PAGE_URL . $pageCount;
                $crawler = $client->request('GET', $url);
                $links = $this->getImgLinksFromContent($crawler);

                if (!count($links)) {
                    break;
                }

                $videoLinks = $this->getVideoLinksFromContent($crawler);
                $this->storeLinkImages($links, $companyCam);
                $this->storeLinkVideos($videoLinks, $companyCam);

                $pageCount++;
            } catch (\Throwable $th) {
                Log::debug('GetCompanyCamImages => error', [
                    'message' => $th->getMessage(),
                    'lastRequestUrl' => $companyCamUrl
                ]);
                break;
            }
        } while (true);
    }
}
