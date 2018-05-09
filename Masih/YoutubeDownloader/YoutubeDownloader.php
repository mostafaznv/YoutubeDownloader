<?php

/**
 * Youtube Downloader
 *
 * @author Masih Yeganeh <masihyeganeh@outlook.com>
 * @package YoutubeDownloader
 *
 * @version 2.9.0
 * @license http://opensource.org/licenses/MIT MIT
 */

namespace Masih\YoutubeDownloader;

use Masih\YoutubeDownloader\Mp4\MediaTypes;
use Masih\YoutubeDownloader\Mp4\Mp4;
use Campo\UserAgent;
use Dflydev\ApacheMimeTypes\FlatRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

class YoutubeDownloader
{
	/**
	 * Youtube Video ID
	 * @var string
	 */
	protected $videoId;

	/**
	 * Youtube Playlist ID
	 * @var string
	 */
	protected $playlistId;

    /**
     * Default config if user not specified any
     * @var array
     */
    protected $config = [
        'file_name_language' => 'en'
    ];

	/**
	 * Current downloading video index in playlist
	 * @var integer
	 */
	protected $currentDownloadingVideoIndex = 1;

	/**
	 * Is Passed Url a Playlist or a Video
	 * @var bool
	 */
	protected $isPlaylist = true;

	/**
	 * Video info fetched from Youtube
	 * @var object
	 */
	protected $videoInfo = null;

	/**
	 * Playlist info fetched from Youtube
	 * @var object
	 */
	protected $playlistInfo = null;

	/**
	 * Definition of itags
	 * @var object
	 */
	protected $itags = null;

	/**
	 * Default itag number if user not specified any
	 * @var integer
	 */
	protected $defaultItag = null;

    /**
     * Default caption if user not specified any
     * @var string
     */
    protected $defaultCaptionLanguage = 'en';

	/**
	 * Default caption format
	 * @var string
	 */
	protected $captionFormat = 'srt';

	/**
	 * Path to save videos (without ending slash)
	 * @var string
	 */
	protected $path = 'videos';

	/**
	 * Web client object
	 * @var Client
	 */
	protected $webClient;

	/**
	 * Number of downloaded bytes of file
	 * @var integer
	 */
	protected $downloadedBytes;

	/**
	 * Size of file to be download in bytes
	 * @var integer
	 */
	protected $fileSize;

	/**
	 * Video frame-per-second, needed for "sub" captions
	 * @var integer
	 */
	protected $defaultFPS = 25;

	/**
	 * Is MP4 files editing enabled in finalize phase
	 * @var boolean
	 */
	protected $mp4EditingEnabled = false; // TODO: Enable this when MP4 module is stable

	/**
	 * Auto close edited Mp4 files
	 * @var boolean
	 */
	protected $autoCloseEditedMp4Files = false; // TODO: Delete this if debach's zend-mp3 bug fixes

	/**
	 * Callable function that is called on download progress
	 * @var callable
	 * @todo This shows wrong number for files with above 2GB size
	 */
	public $onProgress;

	/**
	 * Callable function that is called on download complete
	 * @var callable
	 */
	public $onComplete;

	/**
	 * Callable function that is called on finalize complete
	 * @var callable
	 */
	public $onFinalized;

	/**
	 * Callable function that is called for sanitizing filename
	 * @var callable
	 */
	public $sanitizeFileName;

	/**
	 * Instantiates a YoutubeDownloader with a random User-Agent
	 *
	 * @param  string $videoUrl Full Youtube video url or just video ID
	 * @param  array $config config array
     *
	 * @example var downloader = new YoutubeDownloader('gmFn62dr0D8');
	 * @example var downloader = new YoutubeDownloader('http://www.youtube.com/watch?v=gmFn62dr0D8');
	 * @example var downloader = new YoutubeDownloader('http://www.youtube.com/embed/gmFn62dr0D8');
	 * @example var downloader = new YoutubeDownloader('http://www.youtube.com/v/gmFn62dr0D8');
	 * @example var downloader = new YoutubeDownloader('http://youtu.be/gmFn62dr0D8');
	 * @example var downloader = new YoutubeDownloader('PLbjM1u8Yb9I0rK4hkPa9TWe4N_idJOnrJ');
	 * @example var downloader = new YoutubeDownloader('https://www.youtube.com/watch?v=7gY_sq9uOmw&list=PLbjM1u8Yb9I0rK4hkPa9TWe4N_idJOnrJ');
	 * @example var downloader = new YoutubeDownloader('https://www.youtube.com/playlist?list=PLbjM1u8Yb9I0rK4hkPa9TWe4N_idJOnrJ');
	 * @example var downloader = new YoutubeDownloader('https://www.youtube.com/embed/videoseries?list=PLbjM1u8Yb9I0rK4hkPa9TWe4N_idJOnrJ');
	 */
	public function __construct($videoUrl, Array $config = [])
	{
	    $this->setConfig($config);
		$this->webClient = new Client(array(
			'headers' => array('User-Agent' => UserAgent::random())
		));

		$this->onComplete = function ($filePath, $fileSize) {};
		$this->onProgress = function ($downloadedBytes, $fileSize) {};
		$this->onFinalized = function () {};
		$this->sanitizeFileName = function ($fileName) {return $this->pathSafeFilename($fileName);};

		$this->videoId = $this->getVideoIdFromUrl($videoUrl);
		$this->playlistId = $this->getPlaylistIdFromUrl($videoUrl);
		try {
			$this->playlistInfo = $this->getPlaylistInfo();
		} catch (YoutubeException $exception) {
			throw $exception;
		}

		if ($this->playlistInfo === null) {
			$this->isPlaylist = false;
		}
	}

	protected function setConfig(Array $config)
    {
        foreach ($config as $key => $value)
        {
            if (isset($this->config[$key]))
                $this->config[$key] = $value;
        }
    }

	/**
	 * Returns information about known itags
	 *
	 * @return object           known itags information
	 */
	static public function getItags()
	{
		return json_decode(file_get_contents(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'itags.json'));
	}

	/**
	 * Gets information about a given itag
	 *
	 * @param  int $itag Itag identifier
	 * @return object           Information about itag or null if itag id is unknown
	 */
	public function getItagInfo($itag)
	{
		$itag = '' . $itag;
		$itags = $this->getItags();

		if (property_exists($itags, $itag))
			return $itags->$itag;

		return null;
	}

	/**
	 * Cuts playlist id from absolute Youtube playlist url
	 *
	 * @param  string $playlistUrl Full Youtube playlist url
	 * @return string           Playlist ID
	 */
	protected function getPlaylistIdFromUrl($playlistUrl)
	{
		$playlistId = $playlistUrl;
		$urlPart = parse_url($playlistUrl);

		if (isset($urlPart['query']))
		{
			$query = $this->decodeString($urlPart['query']);

			if (isset($query['list']))
				$playlistId = $query['list'];
			elseif (isset($query['p']))
				$playlistId = $query['p'];
		}

		return $playlistId;
	}

	/**
	 * Cuts video id from absolute Youtube or youtu.be video url
	 *
	 * @param  string $videoUrl Full Youtube or youtu.be video url
	 * @return string           Video ID
	 */
	protected function getVideoIdFromUrl($videoUrl)
	{
		$videoId = $videoUrl;
		$urlPart = parse_url($videoUrl);
		$path = $urlPart['path'];
		if (isset($urlPart['host']) && strtolower($urlPart['host']) == 'youtu.be') {
			if (preg_match('/\/([^\/\?]*)/i', $path, $temp))
				$videoId = $temp[1];
		} else {
			if (preg_match('/\/embed\/([^\/\?]*)/i', $path, $temp))
				$videoId = $temp[1];
			elseif (preg_match('/\/v\/([^\/\?]*)/i', $path, $temp))
				$videoId = $temp[1];
			elseif (preg_match('/\/watch/i', $path, $temp) && isset($urlPart['query']))
			{
				$query = $this->decodeString($urlPart['query']);
				$videoId = $query['v'];
			}
		}

		return $videoId;
	}

	/**
	 * Gets information of Youtube playlist
	 *
	 * @throws YoutubeException If Playlist ID is wrong or not exists anymore or it's not viewable anyhow
	 *
	 * @param bool $getDownloadLinks Also get download links and images for each video
	 * @return object           Playlist's title, author, videos, ...
	 */
	public function getPlaylistInfo($getDownloadLinks=false)
	{
		try {
			$url = 'https://www.youtube.com/list_ajax?style=json&action_get_list=1&list=' . $this->playlistId;
			$response = $this->webClient->get($url);
		} catch (GuzzleException $e) {
			if ($e instanceof ClientException && $e->hasResponse()) {
				if ($e->getResponse()->getStatusCode()) return null;
				throw new YoutubeException($e->getResponse()->getReasonPhrase(), 3);
			}
			else
				throw new YoutubeException($e->getMessage(), 3);
		}

		if ($response->getStatusCode() != 200)
			throw new YoutubeException('Couldn\'t get playlist details.', 1);

		$playlistInfo = json_decode($response->getBody());

		if ($getDownloadLinks)
		{
			foreach ($playlistInfo->video as &$video)
			{
				$this->videoId = $video->encrypted_id;
				try {
					$videoInfo = $this->getVideoInfo(true);
				} catch (YoutubeException $e) {
					throw $e;
				}
				$video->image = $videoInfo->image;
				$video->full_formats = $videoInfo->full_formats;
				$video->adaptive_formats = $videoInfo->adaptive_formats;
			}
			$this->videoId = null;
		}

		$playlistInfo->response_type = 'playlist';

		return $playlistInfo;
	}

	/**
	 * Fetches content of passed URL
	 *
	 * @throws YoutubeException If any error happens
	 *
	 * @param  string $url Address of page to fetch
	 * @return ResponseInterface           content of page
	 */
	protected function getUrl($url)
	{
		try {
			$response = $this->webClient->get($url);
		} catch (GuzzleException $e) {
			if ($e instanceof ClientException && $e->hasResponse())
				throw new YoutubeException($e->getResponse()->getReasonPhrase(), 3);
			else
				throw new YoutubeException($e->getMessage(), 3);
		}
		return $response;
	}

	/**
	 * Decodes URL encoded string
	 *
	 * @param  string $input URL encoded string
	 * @return array           decoded string
	 */
	protected function decodeString($input)
	{
		parse_str($input, $result);
		return $result;
	}

	/**
	 * Decrypts encrypted signatures (V8js extension might be needed)
	 *
	 * @throws YoutubeException If any error happens
	 *
	 * @param  string $signature Encrypted signature
	 * @param  JsDecoder $decryptionObject
	 * @return string           decrypted signature
	 */
	protected function decryptSignature($signature, $decryptionObject)
	{
		try {
			return $decryptionObject->decode($signature);
		} catch (YoutubeException $e) {
			throw $e;
		}
	}

	/**
	 * Gets information of Youtube video
	 *
	 * @throws YoutubeException If Video ID is wrong or video not exists anymore or it's not viewable anyhow
	 *
	 * @param  bool $detailed Show more detailed information
	 * @return object           Video's title, images, video length, download links, ...
	 */
	public function getVideoInfo($detailed=false)
	{
		$result = array();

		try {
			$response = $this->getUrl('https://www.youtube.com/get_video_info?' . http_build_query(array(
				'video_id' => $this->videoId,
				'eurl'     => 'https://youtube.googleapis.com/v/' . $this->videoId
			)));
		} catch (YoutubeException $e) {
			throw $e;
		}

		if ($response->getStatusCode() != 200)
			throw new YoutubeException('Couldn\'t get video details.', 1);

		$data = $this->decodeString($response->getBody());

		$failed = (isset($data['status']) && $data['status'] == 'fail');
		$usingCipheredSignature = false;
		if ($failed) {
			if (isset($data['errorcode']) && $data['errorcode'] == '150')
				$usingCipheredSignature = true;
			else
				throw new YoutubeException($data['reason'], $data['errorcode']);
		} elseif (
			(isset($data['use_cipher_signature']) && $data['use_cipher_signature'] == 'True') ||
			(isset($data['probe_url']) && stripos($data['probe_url'], '&signature=') !== false) ||
			(isset($data['fflags']) && stripos($data['fflags'], 'html5_progressive_signature_reload=true') !== false)
		) {
			$usingCipheredSignature = true;
			$failed = true;
		}

		if ($failed) {
			if ($usingCipheredSignature) {
				try {
					$response = $this->getUrl(
						'https://www.youtube.com/watch?v=' . $this->videoId .
						'&gl=US&hl=en&has_verified=1&bpctr=9999999999'
					);
				} catch (YoutubeException $e) {
					throw $e;
				}

				$response = $response->getBody();
				$ytconfig = '';

				if (preg_match('/var bootstrap_data = "\)\]\}\'(\{.*?\})";/i', $response, $matches)) {
					$ytconfig = json_decode(stripslashes($matches[1]), true);
					$ytconfig = $ytconfig['content']['swfcfg'];
				} elseif (preg_match('/ytplayer.config\s*=\s*([^\n]+});ytplayer/i', $response, $matches)) {
					$ytconfig = json_decode($matches[1], true);
				} elseif (preg_match('/class="message">([^<]+)</i', $response, $matches)) {
					throw new YoutubeException(trim($matches[1]), 10);
				}

				$jsAsset = null;
				if (preg_match('/<script src="([^"]+)" name="player\/base"><\/script>/i', $response, $matches)) {
					$jsAsset = $matches[1];
				}

				if (is_array($ytconfig)) {
					$data = array_merge($data, $ytconfig['args']);
					if (isset($ytconfig['assets']['js']))
						$jsAsset = $ytconfig['assets']['js'];
				}

				if ($detailed && $jsAsset !== null) {
					if (strlen($jsAsset) > 1 && substr($jsAsset, 0, 2) == '//')
						$jsAsset = 'https:' . $jsAsset;
					else
						$jsAsset = 'https://s.ytimg.com' . $jsAsset;
					try {
						$response = $this->getUrl($jsAsset);
					} catch (YoutubeException $e) {
						throw $e;
					}
					$code = $response->getBody();

					try {
						$decoder = new JsDecoder($jsAsset);
						if (!$decoder->isInitialized())
							$decoder->parseJsCode($code);
						$data['decryptionObject'] = $decoder;
					} catch (YoutubeException $e) {
						// Throwing this exception may not bee a good idea
						// the video still might be downloaded without decryption
					}
				}
			} else
				throw new YoutubeException($data['reason'], $data['errorcode']);
		}

		$result['title'] = trim($data['title']);
		$result['image'] = array(
			'max_resolution' => 'https://i.ytimg.com/vi/' . $this->videoId . '/maxresdefault.jpg',
			'high_quality' => 'https://i.ytimg.com/vi/' . $this->videoId . '/hqdefault.jpg',
			'medium_quality' => 'https://i.ytimg.com/vi/' . $this->videoId . '/mqdefault.jpg',
			'standard' => 'https://i.ytimg.com/vi/' . $this->videoId . '/sddefault.jpg',
			'thumbnails' => array(
				'https://i.ytimg.com/vi/' . $this->videoId . '/default.jpg',
				'https://i.ytimg.com/vi/' . $this->videoId . '/0.jpg',
				'https://i.ytimg.com/vi/' . $this->videoId . '/1.jpg',
				'https://i.ytimg.com/vi/' . $this->videoId . '/2.jpg',
				'https://i.ytimg.com/vi/' . $this->videoId . '/3.jpg'
			)
		);
		$result['length_seconds'] = $data['length_seconds'];
		$result['duration'] = vsprintf('%02d:%02d:%02d', $this->parseFloatTime($result['length_seconds']));
		$result['video_id'] = $data['video_id'];
		$result['views'] = $data['view_count'];
		$result['rating'] = round($data['avg_rating']);
		$result['author'] = trim($data['author']);

		$result['captions'] = array();
		if (isset($data['has_cc']) && $data['has_cc'] === 'True')
			$result['captions'] = $this->getCaptions($data, $detailed);

		$sanitizer = &$this->sanitizeFileName;
		$filename = $sanitizer($result['title']);

		$isLive = false;

		if (isset($data['ps']) && $data['ps'] == 'live') $isLive = true;
		if (isset($data['hlsdvr']) && $data['hlsdvr'] == '1') $isLive = true;
		if (isset($data['live_playback']) && $data['live_playback'] == '1') $isLive = true;

		if ($isLive)
		{
			if (!isset($data['hlsvp']))
				throw new YoutubeException('This live event is over.', 2);

			$result['stream_url'] = $data['hlsvp'];
		}
		else
		{
			$stream_maps = array();
			if (isset($data['url_encoded_fmt_stream_map']))
				$stream_maps = explode(',', $data['url_encoded_fmt_stream_map']);
			foreach ($stream_maps as $key => $value) {
				$stream_maps[$key] = $this->decodeString($value);

				if (isset($data['decryptionObject']) && isset($stream_maps[$key]['s'])) {
					try {
						$stream_maps[$key]['url'] .= '&signature=' . $this->decryptSignature(
							$stream_maps[$key]['s'], // Encrypted signature,
							$data['decryptionObject'] // Decryption object
						);
						// TODO: $stream_maps[$key]['url'] .= '&title=' . urlencode($fileName)
					} catch (YoutubeException $e) {
						throw $e;
					}
					unset($stream_maps[$key]['s']);
				} elseif (isset($stream_maps[$key]['sig'])) {
					$stream_maps[$key]['url'] .= '&signature=' . $stream_maps[$key]['sig'];
					unset($stream_maps[$key]['sig']);
				}

				$typeParts = explode(';', $stream_maps[$key]['type']);
				// TODO: Use container of known itags as extension here
				$stream_maps[$key]['filename'] = $filename . '.' . $this->getExtension(trim($typeParts[0]));

				$stream_maps[$key] = (object) $stream_maps[$key];
			}
			$result['full_formats'] = $stream_maps;

			$adaptive_fmts = array();
			if (isset($data['adaptive_fmts']))
				$adaptive_fmts = explode(',', $data['adaptive_fmts']);
			foreach ($adaptive_fmts as $key => $value) {
				$adaptive_fmts[$key] = $this->decodeString($value);

				if (isset($data['decryptionObject']) && isset($adaptive_fmts[$key]['s'])) {
					try {
						$adaptive_fmts[$key]['url'] .= '&signature=' . $this->decryptSignature(
							$adaptive_fmts[$key]['s'], // Encrypted signature,
							$data['decryptionObject'] // Decryption object
						);
						// TODO: $adaptive_fmts[$key]['url'] .= '&title=' . urlencode($fileName)
					} catch (YoutubeException $e) {
						throw $e;
					}
					unset($adaptive_fmts[$key]['s']);
				}

				$typeParts = explode(';', $adaptive_fmts[$key]['type']);
				// TODO: Use container of known itags as extension here
				$adaptive_fmts[$key]['filename'] = $filename . '.' . $this->getExtension(trim($typeParts[0]));

				$adaptive_fmts[$key] = (object) $adaptive_fmts[$key];
			}
			$result['adaptive_formats'] = $adaptive_fmts;
		}

		if (isset($data['decryptionObject']))
			unset($data['decryptionObject']);

		$result['video_url'] = 'https://www.youtube.com/watch?v=' . $this->videoId;

		$result = (object) $result;
		$this->videoInfo = $result;

		$result->response_type = 'video';

		return $result;
	}

	/**
	 * Gets information of Youtube video or playlist
	 *
	 * @throws YoutubeException If Video ID or Playlist Id is wrong or not exists anymore or it's not viewable anyhow
	 *
	 * @param bool $getDownloadLinks Also get download links and images for each video
	 * @return object           Video's title, images, video length, download links, ... or Playlist's title, author, videos, ...
	 */
	public function getInfo($getDownloadLinks=false)
	{
		try {
			if ($this->isPlaylist)
				return $this->getPlaylistInfo($getDownloadLinks);
			return $this->getVideoInfo($getDownloadLinks);
		} catch (YoutubeException $e) {
			throw $e;
		}
	}

	/**
	 * Gets caption of Youtube video and returns it as an object
	 *
	 * @param  array $videoInfo Parsed video info sent by Youtube
	 * @param  bool $detailed Caption track in video info
	 * @return array Captions list
	 */
	protected function getCaptions($videoInfo, $detailed=false) {
		$index = 0;
		$captions = array();
		$captionTracks = explode(',', $videoInfo['caption_tracks']);

		foreach($captionTracks as $captionTrack) {
			$decodedTrack = $this->decodeString($captionTrack);

			$language = $index++;

			if (isset($decodedTrack['lc']))
				$language = $decodedTrack['lc'];

			if (isset($decodedTrack['v']) && $decodedTrack['v'][0] != '.')
				$language = $decodedTrack['v'];

			if ($detailed)
				$captions[$language] = $decodedTrack;
			else
				$captions[$language] = $decodedTrack['n'];
		}

		return $captions;
	}

	/**
	 * Gets caption of Youtube video and returns it as an object
	 *
	 * @param  string $captionTrack Detailed caption track
	 * @return object           Caption as an object
	 */
	protected function parseCaption($captionTrack)
	{
		if (!isset($captionTrack['u'])) return null;

		try {
			$response = $this->webClient->get($captionTrack['u']);
		} catch (GuzzleException $e) {
			if ($e instanceof ClientException && $e->hasResponse()) {
				throw new YoutubeException($e->getResponse()->getReasonPhrase(), 3);
			}
			else
				throw new YoutubeException($e->getMessage(), 3);
		}
		$caption = array();
		$xml = simplexml_load_string($response->getBody());
		foreach ($xml->text as $element) {
			$item = array();
			$item['text'] = html_entity_decode($element . "", ENT_QUOTES | ENT_XHTML);
			$attributes = $element->attributes();
			$item['start'] = floatval($attributes['start'] . "");
			$item['duration'] = floatval(isset($attributes['dur']) ? $attributes['dur'] . "" : '1.0');
			$item['end'] = $item['start'] + $item['duration'];
			array_push($caption, $item);
		}
		$result = array();
		$result['title'] = $captionTrack['n'];
		$result['caption'] = $caption;
		return ((object) $result);
	}

	/**
	 * Converts parsed caption track to desired caption format
	 *
	 * @param  array $captionLines Parsed caption
	 * @param  string $format Caption format, including "srt" (default), "sub", "ass"
	 * @return object           Converted caption and it's file extension
	 */
	protected function formatCaption($captionLines, $format=null)
	{
		$result = array();
		$result['caption'] = '';
		$result['extension'] = 'txt';

		if ($format === null) $format = $this->captionFormat;

		if ($format == 'srt') {
			$result['extension'] = 'srt';

			$sequence = 1;
			foreach ($captionLines as $captionLine) {
				$start = $this->parseFloatTime($captionLine['start']);
				$end = $this->parseFloatTime($captionLine['end']);

				$result['caption'] .= ($sequence++) . "\n";
				$result['caption'] .= str_replace('.', ',', vsprintf('%02d:%02d:%02.3f', $start)) . ' --> ';
				$result['caption'] .= str_replace('.', ',', vsprintf('%02d:%02d:%02.3f', $end)) . "\n";
				$result['caption'] .= $captionLine['text'] . "\n\n";
			}
			$result['caption'] = trim($result['caption']);
		} elseif ($format == 'sub') {
			$result['extension'] = 'sub';

			foreach ($captionLines as $captionLine) {
				$result['caption'] .= '{' . intval($captionLine['start'] * $this->defaultFPS) . '}';
				$result['caption'] .= '{' . intval($captionLine['end'] * $this->defaultFPS) . '}';
				$result['caption'] .= $captionLine['text'] . "\n";
			}
			$result['caption'] = trim($result['caption']);
		} elseif ($format == 'ass') {
			$result['extension'] = 'ass';
			$result['caption'] = <<<EOF
[Script Info]
ScriptType: v4.00+
Collisions: Normal
PlayDepth: 0
Timer: 100,0000
Video Aspect Ratio: 0
WrapStyle: 0
ScaledBorderAndShadow: no

[V4+ Styles]
Format: Name,Fontname,Fontsize,PrimaryColour,SecondaryColour,OutlineColour,BackColour,Bold,Italic,Underline,StrikeOut,ScaleX,ScaleY,Spacing,Angle,BorderStyle,Outline,Shadow,Alignment,MarginL,MarginR,MarginV,Encoding
Style: Default,Arial,16,&H00FFFFFF,&H00FFFFFF,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,3,0,2,10,10,10,0
Style: Top,Arial,16,&H00F9FFFF,&H00FFFFFF,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,3,0,8,10,10,10,0
Style: Mid,Arial,16,&H0000FFFF,&H00FFFFFF,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,3,0,5,10,10,10,0
Style: Bot,Arial,16,&H00F9FFF9,&H00FFFFFF,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,3,0,2,10,10,10,0

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text

EOF;
			foreach ($captionLines as $captionLine) {
				$start = $this->parseFloatTime($captionLine['start']);
				$end = $this->parseFloatTime($captionLine['end']);

				$result['caption'] .= 'Dialogue: 0,';
				$result['caption'] .= vsprintf('%d:%02d:%02.2f', $start) . ',';
				$result['caption'] .= vsprintf('%d:%02d:%02.2f', $end) . ',Bot,,0000,0000,0000,,';
				$result['caption'] .= $captionLine['text'] . "\n";
			}
		}

		return ((object) $result);
	}

	/**
	 * Downloads caption of the video in selected language
	 *
	 * @param  array $captions Captions data of video
	 * @param  string $language User selected language
	 * @param  string $filename Caption file name
	 */
	protected function downloadCaption($captions, $language, $filename)
	{
		if ($language === null) $language = $this->defaultCaptionLanguage;
		if (!array_key_exists($language, $captions))
			$captionData = array_shift($captions);
		else
			$captionData = $captions[$language];

		$captionData = $this->parseCaption($captionData);
		if ($captionData !== null) {

			$captionData = $this->formatCaption($captionData->caption);

			$filename = substr($filename, 0, strrpos($filename, '.')) . '.' . $captionData->extension;
			$path = $this->path . DIRECTORY_SEPARATOR . $filename;

			file_put_contents($path, $captionData->caption);
		}
	}

	/**
	 * Removes unsafe characters from file name and convert none english words to english (configurable)
	 *
	 * @param  string $string Path unsafe file name
     * @param  string $separator
	 * @return string         Path Safe file name
	 *
	 */
	public function pathSafeFilename($string, $separator = '-')
	{
        $string = self::ascii($string, $this->config['file_name_language']);

        // Convert all dashes/underscores into separator
        $flip = $separator == '-' ? '_' : '-';

        $string = preg_replace('![' . preg_quote($flip) . ']+!u', $separator, $string);

        // Replace @ with the word 'at'
        $string = str_replace('@', $separator . 'at' . $separator, $string);

        // Remove all characters that are not the separator, letters, numbers, or whitespace.
        $string = preg_replace('![^' . preg_quote($separator) . '\pL\pN\s]+!u', '', mb_strtolower($string));

        // Replace all separator characters and whitespace by a single separator
        $string = preg_replace('![' . preg_quote($separator) . '\s]+!u', $separator, $string);

        return trim($string, $separator);
	}

    /**
     * Transliterate a UTF-8 value to ASCII.
     *
     * Note: Adapted from laravel/framework with some customizations to support farsi and arabic.
     *
     * @see https://github.com/laravel/framework/blob/5.6/README.md
     *
     * @param  string  $value
     * @param  string  $language
     * @return string
     */
    protected function ascii($value, $language = 'en')
    {
        $languageSpecific = static::languageSpecificCharsArray($language);

        if (!is_null($languageSpecific))
            $value = str_replace($languageSpecific[0], $languageSpecific[1], $value);

        foreach (static::charsArray($language) as $key => $val)
            $value = str_replace($val, $key, $value);

        if (in_array($language, ['fa', 'ar']))
            return $this->faRegex($value);
        return preg_replace('/[^\x20-\x7E]/u', '', $value);
    }

    /**
     * Remove unsafe characters except farsi and arabic characters
     *
     * @param $value
     * @return mixed
     */
    protected function faRegex($value)
    {
        return preg_replace('/[^!(|۰|۱|۲|۳|۴|۵|۶|۷|۸|۹|٤|٥|٦|ا|أ|ب|د|ض|إ|ف|گ|ح|ه|ی|ｉ|ج|ج|ق|ك|ک|ل|م|ن|و|پ|ر|س|ص|ت|ط|ي|ز|آ|ع|چ|غ|خ|ؤ|ش|ث|ذ|ظ|هٔ|ة|ئ|اً|ژ|ء|)\x20-\x7E]/u', '', $value);
    }

    /**
     * Returns the language specific replacements for the ascii method.
     *
     * Note: Adapted from Stringy\Stringy
     *
     * @see https://github.com/danielstjules/Stringy/blob/3.1.0/LICENSE.txt
     *
     * @param  string  $language
     * @return array|null
     */
    protected function languageSpecificCharsArray($language)
    {
        static $languageSpecific;

        if (!isset($languageSpecific))
        {
            $languageSpecific = [
                'bg' => [
                    ['х', 'Х', 'щ', 'Щ', 'ъ', 'Ъ', 'ь', 'Ь'],
                    ['h', 'H', 'sht', 'SHT', 'a', 'А', 'y', 'Y'],
                ],
                'de' => [
                    ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü'],
                    ['ae', 'oe', 'ue', 'AE', 'OE', 'UE'],
                ],
            ];
        }

        return $languageSpecific[$language] ?? null;
    }

    /**
     * Returns the replacements for the ascii method.
     *
     * Note: Adapted from Stringy\Stringy with some customizations to support farsi and arabic.
     *
     * @see https://github.com/danielstjules/Stringy/blob/3.1.0/LICENSE.txt
     *
     * @return array
     */
    protected function charsArray($language)
    {
        static $charsArray;

        if (isset($charsArray))
            return $charsArray;

        if (in_array($language, ['fa', 'ar']))
        {
            return $charsArray = [
                '0' => ['°', '₀', '０'],
                '1' => ['¹', '₁', '１'],
                '2' => ['²', '₂', '２'],
                '3' => ['³', '₃', '３'],
                '4' => ['⁴', '₄', '４'],
                '5' => ['⁵', '₅', '５'],
                '6' => ['⁶', '₆', '６'],
                '7' => ['⁷', '₇', '７'],
                '8' => ['⁸', '₈', '８'],
                '9' => ['⁹', '₉', '９'],
                'a' => ['à', 'á', 'ả', 'ã', 'ạ', 'ă', 'ắ', 'ằ', 'ẳ', 'ẵ', 'ặ', 'â', 'ấ', 'ầ', 'ẩ', 'ẫ', 'ậ', 'ā', 'ą', 'å', 'α', 'ά', 'ἀ', 'ἁ', 'ἂ', 'ἃ', 'ἄ', 'ἅ', 'ἆ', 'ἇ', 'ᾀ', 'ᾁ', 'ᾂ', 'ᾃ', 'ᾄ', 'ᾅ', 'ᾆ', 'ᾇ', 'ὰ', 'ά', 'ᾰ', 'ᾱ', 'ᾲ', 'ᾳ', 'ᾴ', 'ᾶ', 'ᾷ', 'а', 'အ', 'ာ', 'ါ', 'ǻ', 'ǎ', 'ª', 'ა', 'अ', 'ａ', 'ä'],
                'b' => ['б', 'β', 'ဗ', 'ბ', 'ｂ'],
                'c' => ['ç', 'ć', 'č', 'ĉ', 'ċ', 'ｃ'],
                'd' => ['ď', 'ð', 'đ', 'ƌ', 'ȡ', 'ɖ', 'ɗ', 'ᵭ', 'ᶁ', 'ᶑ', 'д', 'δ', 'ဍ', 'ဒ', 'დ', 'ｄ'],
                'e' => ['é', 'è', 'ẻ', 'ẽ', 'ẹ', 'ê', 'ế', 'ề', 'ể', 'ễ', 'ệ', 'ë', 'ē', 'ę', 'ě', 'ĕ', 'ė', 'ε', 'έ', 'ἐ', 'ἑ', 'ἒ', 'ἓ', 'ἔ', 'ἕ', 'ὲ', 'έ', 'е', 'ё', 'э', 'є', 'ə', 'ဧ', 'ေ', 'ဲ', 'ე', 'ए', 'ｅ'],
                'f' => ['ф', 'φ', 'ƒ', 'ფ', 'ｆ'],
                'g' => ['ĝ', 'ğ', 'ġ', 'ģ', 'г', 'ґ', 'γ', 'ဂ', 'გ', 'ｇ'],
                'h' => ['ĥ', 'ħ', 'η', 'ή', 'ဟ', 'ှ', 'ჰ', 'ｈ'],
                'i' => ['í', 'ì', 'ỉ', 'ĩ', 'ị', 'î', 'ï', 'ī', 'ĭ', 'į', 'ı', 'ι', 'ί', 'ϊ', 'ΐ', 'ἰ', 'ἱ', 'ἲ', 'ἳ', 'ἴ', 'ἵ', 'ἶ', 'ἷ', 'ὶ', 'ί', 'ῐ', 'ῑ', 'ῒ', 'ΐ', 'ῖ', 'ῗ', 'і', 'ї', 'и', 'ဣ', 'ိ', 'ီ', 'ည်', 'ǐ', 'ი', 'इ', 'ｉ'],
                'j' => ['ĵ', 'ј', 'Ј', 'ჯ', 'ｊ'],
                'k' => ['ķ', 'ĸ', 'к', 'κ', 'Ķ', 'က', 'კ', 'ქ', 'ｋ'],
                'l' => ['ł', 'ľ', 'ĺ', 'ļ', 'ŀ', 'л', 'λ', 'လ', 'ლ', 'ｌ'],
                'm' => ['м', 'μ', 'မ', 'მ', 'ｍ'],
                'n' => ['ñ', 'ń', 'ň', 'ņ', 'ŉ', 'ŋ', 'ν', 'н', 'န', 'ნ', 'ｎ'],
                'o' => ['ó', 'ò', 'ỏ', 'õ', 'ọ', 'ô', 'ố', 'ồ', 'ổ', 'ỗ', 'ộ', 'ơ', 'ớ', 'ờ', 'ở', 'ỡ', 'ợ', 'ø', 'ō', 'ő', 'ŏ', 'ο', 'ὀ', 'ὁ', 'ὂ', 'ὃ', 'ὄ', 'ὅ', 'ὸ', 'ό', 'о', 'θ', 'ို', 'ǒ', 'ǿ', 'º', 'ო', 'ओ', 'ｏ', 'ö'],
                'p' => ['п', 'π', 'ပ', 'პ', 'ｐ'],
                'q' => ['ყ', 'ｑ'],
                'r' => ['ŕ', 'ř', 'ŗ', 'р', 'ρ', 'რ', 'ｒ'],
                's' => ['ś', 'š', 'ş', 'с', 'σ', 'ș', 'ς', 'စ', 'ſ', 'ს', 'ｓ'],
                't' => ['ť', 'ţ', 'т', 'τ', 'ț', 'ဋ', 'တ', 'ŧ', 'თ', 'ტ', 'ｔ'],
                'u' => ['ú', 'ù', 'ủ', 'ũ', 'ụ', 'ư', 'ứ', 'ừ', 'ử', 'ữ', 'ự', 'û', 'ū', 'ů', 'ű', 'ŭ', 'ų', 'µ', 'у', 'ဉ', 'ု', 'ူ', 'ǔ', 'ǖ', 'ǘ', 'ǚ', 'ǜ', 'უ', 'उ', 'ｕ', 'ў', 'ü'],
                'v' => ['в', 'ვ', 'ϐ', 'ｖ'],
                'w' => ['ŵ', 'ω', 'ώ', 'ဝ', 'ွ', 'ｗ'],
                'x' => ['χ', 'ξ', 'ｘ'],
                'y' => ['ý', 'ỳ', 'ỷ', 'ỹ', 'ỵ', 'ÿ', 'ŷ', 'й', 'ы', 'υ', 'ϋ', 'ύ', 'ΰ', 'ယ', 'ｙ'],
                'z' => ['ź', 'ž', 'ż', 'з', 'ζ', 'ဇ', 'ზ', 'ｚ'],
                'aa' => ['आ'],
                'ae' => ['æ', 'ǽ'],
                'ai' => ['ऐ'],
                'ch' => ['ч', 'ჩ', 'ჭ'],
                'dj' => ['ђ', 'đ'],
                'dz' => ['џ', 'ძ'],
                'ei' => ['ऍ'],
                'gh' => ['ღ'],
                'ii' => ['ई'],
                'ij' => ['ĳ'],
                'kh' => ['х', 'ხ'],
                'lj' => ['љ'],
                'nj' => ['њ'],
                'oe' => ['ö', 'œ'],
                'oi' => ['ऑ'],
                'oii' => ['ऒ'],
                'ps' => ['ψ'],
                'sh' => ['ш', 'შ'],
                'shch' => ['щ'],
                'ss' => ['ß'],
                'sx' => ['ŝ'],
                'th' => ['þ', 'ϑ'],
                'ts' => ['ц', 'ც', 'წ'],
                'ue' => ['ü'],
                'uu' => ['ऊ'],
                'ya' => ['я'],
                'yu' => ['ю'],
                'zh' => ['ж', 'ჟ'],
                '(c)' => ['©'],
                'A' => ['Á', 'À', 'Ả', 'Ã', 'Ạ', 'Ă', 'Ắ', 'Ằ', 'Ẳ', 'Ẵ', 'Ặ', 'Â', 'Ấ', 'Ầ', 'Ẩ', 'Ẫ', 'Ậ', 'Å', 'Ā', 'Ą', 'Α', 'Ά', 'Ἀ', 'Ἁ', 'Ἂ', 'Ἃ', 'Ἄ', 'Ἅ', 'Ἆ', 'Ἇ', 'ᾈ', 'ᾉ', 'ᾊ', 'ᾋ', 'ᾌ', 'ᾍ', 'ᾎ', 'ᾏ', 'Ᾰ', 'Ᾱ', 'Ὰ', 'Ά', 'ᾼ', 'А', 'Ǻ', 'Ǎ', 'Ａ', 'Ä'],
                'B' => ['Б', 'Β', 'ब', 'Ｂ'],
                'C' => ['Ç', 'Ć', 'Č', 'Ĉ', 'Ċ', 'Ｃ'],
                'D' => ['Ď', 'Ð', 'Đ', 'Ɖ', 'Ɗ', 'Ƌ', 'ᴅ', 'ᴆ', 'Д', 'Δ', 'Ｄ'],
                'E' => ['É', 'È', 'Ẻ', 'Ẽ', 'Ẹ', 'Ê', 'Ế', 'Ề', 'Ể', 'Ễ', 'Ệ', 'Ë', 'Ē', 'Ę', 'Ě', 'Ĕ', 'Ė', 'Ε', 'Έ', 'Ἐ', 'Ἑ', 'Ἒ', 'Ἓ', 'Ἔ', 'Ἕ', 'Έ', 'Ὲ', 'Е', 'Ё', 'Э', 'Є', 'Ə', 'Ｅ'],
                'F' => ['Ф', 'Φ', 'Ｆ'],
                'G' => ['Ğ', 'Ġ', 'Ģ', 'Г', 'Ґ', 'Γ', 'Ｇ'],
                'H' => ['Η', 'Ή', 'Ħ', 'Ｈ'],
                'I' => ['Í', 'Ì', 'Ỉ', 'Ĩ', 'Ị', 'Î', 'Ï', 'Ī', 'Ĭ', 'Į', 'İ', 'Ι', 'Ί', 'Ϊ', 'Ἰ', 'Ἱ', 'Ἳ', 'Ἴ', 'Ἵ', 'Ἶ', 'Ἷ', 'Ῐ', 'Ῑ', 'Ὶ', 'Ί', 'И', 'І', 'Ї', 'Ǐ', 'ϒ', 'Ｉ'],
                'J' => ['Ｊ'],
                'K' => ['К', 'Κ', 'Ｋ'],
                'L' => ['Ĺ', 'Ł', 'Л', 'Λ', 'Ļ', 'Ľ', 'Ŀ', 'ल', 'Ｌ'],
                'M' => ['М', 'Μ', 'Ｍ'],
                'N' => ['Ń', 'Ñ', 'Ň', 'Ņ', 'Ŋ', 'Н', 'Ν', 'Ｎ'],
                'O' => ['Ó', 'Ò', 'Ỏ', 'Õ', 'Ọ', 'Ô', 'Ố', 'Ồ', 'Ổ', 'Ỗ', 'Ộ', 'Ơ', 'Ớ', 'Ờ', 'Ở', 'Ỡ', 'Ợ', 'Ø', 'Ō', 'Ő', 'Ŏ', 'Ο', 'Ό', 'Ὀ', 'Ὁ', 'Ὂ', 'Ὃ', 'Ὄ', 'Ὅ', 'Ὸ', 'Ό', 'О', 'Θ', 'Ө', 'Ǒ', 'Ǿ', 'Ｏ', 'Ö'],
                'P' => ['П', 'Π', 'Ｐ'],
                'Q' => ['Ｑ'],
                'R' => ['Ř', 'Ŕ', 'Р', 'Ρ', 'Ŗ', 'Ｒ'],
                'S' => ['Ş', 'Ŝ', 'Ș', 'Š', 'Ś', 'С', 'Σ', 'Ｓ'],
                'T' => ['Ť', 'Ţ', 'Ŧ', 'Ț', 'Т', 'Τ', 'Ｔ'],
                'U' => ['Ú', 'Ù', 'Ủ', 'Ũ', 'Ụ', 'Ư', 'Ứ', 'Ừ', 'Ử', 'Ữ', 'Ự', 'Û', 'Ū', 'Ů', 'Ű', 'Ŭ', 'Ų', 'У', 'Ǔ', 'Ǖ', 'Ǘ', 'Ǚ', 'Ǜ', 'Ｕ', 'Ў', 'Ü'],
                'V' => ['В', 'Ｖ'],
                'W' => ['Ω', 'Ώ', 'Ŵ', 'Ｗ'],
                'X' => ['Χ', 'Ξ', 'Ｘ'],
                'Y' => ['Ý', 'Ỳ', 'Ỷ', 'Ỹ', 'Ỵ', 'Ÿ', 'Ῠ', 'Ῡ', 'Ὺ', 'Ύ', 'Ы', 'Й', 'Υ', 'Ϋ', 'Ŷ', 'Ｙ'],
                'Z' => ['Ź', 'Ž', 'Ż', 'З', 'Ζ', 'Ｚ'],
                'AE' => ['Æ', 'Ǽ'],
                'Ch' => ['Ч'],
                'Dj' => ['Ђ'],
                'Dz' => ['Џ'],
                'Gx' => ['Ĝ'],
                'Hx' => ['Ĥ'],
                'Ij' => ['Ĳ'],
                'Jx' => ['Ĵ'],
                'Kh' => ['Х'],
                'Lj' => ['Љ'],
                'Nj' => ['Њ'],
                'Oe' => ['Œ'],
                'Ps' => ['Ψ'],
                'Sh' => ['Ш'],
                'Shch' => ['Щ'],
                'Ss' => ['ẞ'],
                'Th' => ['Þ'],
                'Ts' => ['Ц'],
                'Ya' => ['Я'],
                'Yu' => ['Ю'],
                'Zh' => ['Ж'],
                ' ' => ["\xC2\xA0", "\xE2\x80\x80", "\xE2\x80\x81", "\xE2\x80\x82", "\xE2\x80\x83", "\xE2\x80\x84", "\xE2\x80\x85", "\xE2\x80\x86", "\xE2\x80\x87", "\xE2\x80\x88", "\xE2\x80\x89", "\xE2\x80\x8A", "\xE2\x80\xAF", "\xE2\x81\x9F", "\xE3\x80\x80", "\xEF\xBE\xA0"],
            ];
        }
        else
        {
            return $charsArray = [
                '0' => ['°', '₀',  '０'],
                '1' => ['¹', '₁',  '１'],
                '2' => ['²', '₂',  '２'],
                '3' => ['³', '₃',  '３'],
                '4' => ['⁴', '₄',  '٤', '４'],
                '5' => ['⁵', '₅',  '٥', '５'],
                '6' => ['⁶', '₆',  '٦', '６'],
                '7' => ['⁷', '₇',  '７'],
                '8' => ['⁸', '₈',  '８'],
                '9' => ['⁹', '₉',  '９'],
                'a' => ['à', 'á', 'ả', 'ã', 'ạ', 'ă', 'ắ', 'ằ', 'ẳ', 'ẵ', 'ặ', 'â', 'ấ', 'ầ', 'ẩ', 'ẫ', 'ậ', 'ā', 'ą', 'å', 'α', 'ά', 'ἀ', 'ἁ', 'ἂ', 'ἃ', 'ἄ', 'ἅ', 'ἆ', 'ἇ', 'ᾀ', 'ᾁ', 'ᾂ', 'ᾃ', 'ᾄ', 'ᾅ', 'ᾆ', 'ᾇ', 'ὰ', 'ά', 'ᾰ', 'ᾱ', 'ᾲ', 'ᾳ', 'ᾴ', 'ᾶ', 'ᾷ', 'а', 'أ', 'အ', 'ာ', 'ါ', 'ǻ', 'ǎ', 'ª', 'ა', 'अ', 'ا', 'ａ', 'ä'],
                'b' => ['б', 'β', 'ب', 'ဗ', 'ბ', 'ｂ'],
                'c' => ['ç', 'ć', 'č', 'ĉ', 'ċ', 'ｃ'],
                'd' => ['ď', 'ð', 'đ', 'ƌ', 'ȡ', 'ɖ', 'ɗ', 'ᵭ', 'ᶁ', 'ᶑ', 'д', 'δ', 'د', 'ض', 'ဍ', 'ဒ', 'დ', 'ｄ'],
                'e' => ['é', 'è', 'ẻ', 'ẽ', 'ẹ', 'ê', 'ế', 'ề', 'ể', 'ễ', 'ệ', 'ë', 'ē', 'ę', 'ě', 'ĕ', 'ė', 'ε', 'έ', 'ἐ', 'ἑ', 'ἒ', 'ἓ', 'ἔ', 'ἕ', 'ὲ', 'έ', 'е', 'ё', 'э', 'є', 'ə', 'ဧ', 'ေ', 'ဲ', 'ე', 'ए', 'إ', 'ئ', 'ｅ'],
                'f' => ['ф', 'φ', 'ف', 'ƒ', 'ფ', 'ｆ'],
                'g' => ['ĝ', 'ğ', 'ġ', 'ģ', 'г', 'ґ', 'γ', 'ဂ', 'გ', 'گ', 'ｇ'],
                'h' => ['ĥ', 'ħ', 'η', 'ή', 'ح', 'ه', 'ဟ', 'ှ', 'ჰ', 'ｈ'],
                'i' => ['í', 'ì', 'ỉ', 'ĩ', 'ị', 'î', 'ï', 'ī', 'ĭ', 'į', 'ı', 'ι', 'ί', 'ϊ', 'ΐ', 'ἰ', 'ἱ', 'ἲ', 'ἳ', 'ἴ', 'ἵ', 'ἶ', 'ἷ', 'ὶ', 'ί', 'ῐ', 'ῑ', 'ῒ', 'ΐ', 'ῖ', 'ῗ', 'і', 'ї', 'и', 'ဣ', 'ိ', 'ီ', 'ည်', 'ǐ', 'ი', 'इ', 'ی', 'ｉ'],
                'j' => ['ĵ', 'ј', 'Ј', 'ჯ', 'ج', 'ｊ'],
                'k' => ['ķ', 'ĸ', 'к', 'κ', 'Ķ', 'ق', 'ك', 'က', 'კ', 'ქ', 'ک', 'ｋ'],
                'l' => ['ł', 'ľ', 'ĺ', 'ļ', 'ŀ', 'л', 'λ', 'ل', 'လ', 'ლ', 'ｌ'],
                'm' => ['м', 'μ', 'م', 'မ', 'მ', 'ｍ'],
                'n' => ['ñ', 'ń', 'ň', 'ņ', 'ŉ', 'ŋ', 'ν', 'н', 'ن', 'န', 'ნ', 'ｎ'],
                'o' => ['ó', 'ò', 'ỏ', 'õ', 'ọ', 'ô', 'ố', 'ồ', 'ổ', 'ỗ', 'ộ', 'ơ', 'ớ', 'ờ', 'ở', 'ỡ', 'ợ', 'ø', 'ō', 'ő', 'ŏ', 'ο', 'ὀ', 'ὁ', 'ὂ', 'ὃ', 'ὄ', 'ὅ', 'ὸ', 'ό', 'о', 'و', 'θ', 'ို', 'ǒ', 'ǿ', 'º', 'ო', 'ओ', 'ｏ', 'ö'],
                'p' => ['п', 'π', 'ပ', 'პ', 'پ', 'ｐ'],
                'q' => ['ყ', 'ｑ'],
                'r' => ['ŕ', 'ř', 'ŗ', 'р', 'ρ', 'ر', 'რ', 'ｒ'],
                's' => ['ś', 'š', 'ş', 'с', 'σ', 'ș', 'ς', 'س', 'ص', 'စ', 'ſ', 'ს', 'ｓ'],
                't' => ['ť', 'ţ', 'т', 'τ', 'ț', 'ت', 'ط', 'ဋ', 'တ', 'ŧ', 'თ', 'ტ', 'ｔ'],
                'u' => ['ú', 'ù', 'ủ', 'ũ', 'ụ', 'ư', 'ứ', 'ừ', 'ử', 'ữ', 'ự', 'û', 'ū', 'ů', 'ű', 'ŭ', 'ų', 'µ', 'у', 'ဉ', 'ု', 'ူ', 'ǔ', 'ǖ', 'ǘ', 'ǚ', 'ǜ', 'უ', 'उ', 'ｕ', 'ў', 'ü'],
                'v' => ['в', 'ვ', 'ϐ', 'ｖ'],
                'w' => ['ŵ', 'ω', 'ώ', 'ဝ', 'ွ', 'ｗ'],
                'x' => ['χ', 'ξ', 'ｘ'],
                'y' => ['ý', 'ỳ', 'ỷ', 'ỹ', 'ỵ', 'ÿ', 'ŷ', 'й', 'ы', 'υ', 'ϋ', 'ύ', 'ΰ', 'ي', 'ယ', 'ｙ'],
                'z' => ['ź', 'ž', 'ż', 'з', 'ζ', 'ز', 'ဇ', 'ზ', 'ｚ'],
                'aa' => ['ع', 'आ', 'آ'],
                'ae' => ['æ', 'ǽ'],
                'ai' => ['ऐ'],
                'ch' => ['ч', 'ჩ', 'ჭ', 'چ'],
                'dj' => ['ђ', 'đ'],
                'dz' => ['џ', 'ძ'],
                'ei' => ['ऍ'],
                'gh' => ['غ', 'ღ'],
                'ii' => ['ई'],
                'ij' => ['ĳ'],
                'kh' => ['х', 'خ', 'ხ'],
                'lj' => ['љ'],
                'nj' => ['њ'],
                'oe' => ['ö', 'œ', 'ؤ'],
                'oi' => ['ऑ'],
                'oii' => ['ऒ'],
                'ps' => ['ψ'],
                'sh' => ['ш', 'შ', 'ش'],
                'shch' => ['щ'],
                'ss' => ['ß'],
                'sx' => ['ŝ'],
                'th' => ['þ', 'ϑ', 'ث', 'ذ', 'ظ'],
                'ts' => ['ц', 'ც', 'წ'],
                'ue' => ['ü'],
                'uu' => ['ऊ'],
                'ya' => ['я'],
                'yu' => ['ю'],
                'zh' => ['ж', 'ჟ', 'ژ'],
                '(c)' => ['©'],
                'A' => ['Á', 'À', 'Ả', 'Ã', 'Ạ', 'Ă', 'Ắ', 'Ằ', 'Ẳ', 'Ẵ', 'Ặ', 'Â', 'Ấ', 'Ầ', 'Ẩ', 'Ẫ', 'Ậ', 'Å', 'Ā', 'Ą', 'Α', 'Ά', 'Ἀ', 'Ἁ', 'Ἂ', 'Ἃ', 'Ἄ', 'Ἅ', 'Ἆ', 'Ἇ', 'ᾈ', 'ᾉ', 'ᾊ', 'ᾋ', 'ᾌ', 'ᾍ', 'ᾎ', 'ᾏ', 'Ᾰ', 'Ᾱ', 'Ὰ', 'Ά', 'ᾼ', 'А', 'Ǻ', 'Ǎ', 'Ａ', 'Ä'],
                'B' => ['Б', 'Β', 'ब', 'Ｂ'],
                'C' => ['Ç', 'Ć', 'Č', 'Ĉ', 'Ċ', 'Ｃ'],
                'D' => ['Ď', 'Ð', 'Đ', 'Ɖ', 'Ɗ', 'Ƌ', 'ᴅ', 'ᴆ', 'Д', 'Δ', 'Ｄ'],
                'E' => ['É', 'È', 'Ẻ', 'Ẽ', 'Ẹ', 'Ê', 'Ế', 'Ề', 'Ể', 'Ễ', 'Ệ', 'Ë', 'Ē', 'Ę', 'Ě', 'Ĕ', 'Ė', 'Ε', 'Έ', 'Ἐ', 'Ἑ', 'Ἒ', 'Ἓ', 'Ἔ', 'Ἕ', 'Έ', 'Ὲ', 'Е', 'Ё', 'Э', 'Є', 'Ə', 'Ｅ'],
                'F' => ['Ф', 'Φ', 'Ｆ'],
                'G' => ['Ğ', 'Ġ', 'Ģ', 'Г', 'Ґ', 'Γ', 'Ｇ'],
                'H' => ['Η', 'Ή', 'Ħ', 'Ｈ'],
                'I' => ['Í', 'Ì', 'Ỉ', 'Ĩ', 'Ị', 'Î', 'Ï', 'Ī', 'Ĭ', 'Į', 'İ', 'Ι', 'Ί', 'Ϊ', 'Ἰ', 'Ἱ', 'Ἳ', 'Ἴ', 'Ἵ', 'Ἶ', 'Ἷ', 'Ῐ', 'Ῑ', 'Ὶ', 'Ί', 'И', 'І', 'Ї', 'Ǐ', 'ϒ', 'Ｉ'],
                'J' => ['Ｊ'],
                'K' => ['К', 'Κ', 'Ｋ'],
                'L' => ['Ĺ', 'Ł', 'Л', 'Λ', 'Ļ', 'Ľ', 'Ŀ', 'ल', 'Ｌ'],
                'M' => ['М', 'Μ', 'Ｍ'],
                'N' => ['Ń', 'Ñ', 'Ň', 'Ņ', 'Ŋ', 'Н', 'Ν', 'Ｎ'],
                'O' => ['Ó', 'Ò', 'Ỏ', 'Õ', 'Ọ', 'Ô', 'Ố', 'Ồ', 'Ổ', 'Ỗ', 'Ộ', 'Ơ', 'Ớ', 'Ờ', 'Ở', 'Ỡ', 'Ợ', 'Ø', 'Ō', 'Ő', 'Ŏ', 'Ο', 'Ό', 'Ὀ', 'Ὁ', 'Ὂ', 'Ὃ', 'Ὄ', 'Ὅ', 'Ὸ', 'Ό', 'О', 'Θ', 'Ө', 'Ǒ', 'Ǿ', 'Ｏ', 'Ö'],
                'P' => ['П', 'Π', 'Ｐ'],
                'Q' => ['Ｑ'],
                'R' => ['Ř', 'Ŕ', 'Р', 'Ρ', 'Ŗ', 'Ｒ'],
                'S' => ['Ş', 'Ŝ', 'Ș', 'Š', 'Ś', 'С', 'Σ', 'Ｓ'],
                'T' => ['Ť', 'Ţ', 'Ŧ', 'Ț', 'Т', 'Τ', 'Ｔ'],
                'U' => ['Ú', 'Ù', 'Ủ', 'Ũ', 'Ụ', 'Ư', 'Ứ', 'Ừ', 'Ử', 'Ữ', 'Ự', 'Û', 'Ū', 'Ů', 'Ű', 'Ŭ', 'Ų', 'У', 'Ǔ', 'Ǖ', 'Ǘ', 'Ǚ', 'Ǜ', 'Ｕ', 'Ў', 'Ü'],
                'V' => ['В', 'Ｖ'],
                'W' => ['Ω', 'Ώ', 'Ŵ', 'Ｗ'],
                'X' => ['Χ', 'Ξ', 'Ｘ'],
                'Y' => ['Ý', 'Ỳ', 'Ỷ', 'Ỹ', 'Ỵ', 'Ÿ', 'Ῠ', 'Ῡ', 'Ὺ', 'Ύ', 'Ы', 'Й', 'Υ', 'Ϋ', 'Ŷ', 'Ｙ'],
                'Z' => ['Ź', 'Ž', 'Ż', 'З', 'Ζ', 'Ｚ'],
                'AE' => ['Æ', 'Ǽ'],
                'Ch' => ['Ч'],
                'Dj' => ['Ђ'],
                'Dz' => ['Џ'],
                'Gx' => ['Ĝ'],
                'Hx' => ['Ĥ'],
                'Ij' => ['Ĳ'],
                'Jx' => ['Ĵ'],
                'Kh' => ['Х'],
                'Lj' => ['Љ'],
                'Nj' => ['Њ'],
                'Oe' => ['Œ'],
                'Ps' => ['Ψ'],
                'Sh' => ['Ш'],
                'Shch' => ['Щ'],
                'Ss' => ['ẞ'],
                'Th' => ['Þ'],
                'Ts' => ['Ц'],
                'Ya' => ['Я'],
                'Yu' => ['Ю'],
                'Zh' => ['Ж'],
                ' ' => ["\xC2\xA0", "\xE2\x80\x80", "\xE2\x80\x81", "\xE2\x80\x82", "\xE2\x80\x83", "\xE2\x80\x84", "\xE2\x80\x85", "\xE2\x80\x86", "\xE2\x80\x87", "\xE2\x80\x88", "\xE2\x80\x89", "\xE2\x80\x8A", "\xE2\x80\xAF", "\xE2\x81\x9F", "\xE3\x80\x80", "\xEF\xBE\xA0"],
            ];
        }
    }

	/**
	 * Returns file extension of a given mime type
	 *
	 * @uses \Dflydev\ApacheMimeTypes\FlatRepository Mimetype parser library
	 * @param  string $mimetype Mime type
	 * @return string           File extension of given mime type. it will return "mp4" if no extension could be found
	 */
	protected function getExtension($mimetype)
	{
		$mime = new FlatRepository;
		$extension = 'mp4';
		$extensions = $mime->findExtensions($mimetype);
		if (count($extensions))
			$extension = $extensions[0];

		return $extension;
	}

	/**
	 * Just downloads the given url
	 *
	 * @param  string   $url Url of file to download
	 * @param  string   $file Path of file to save to
	 * @param  callable $onProgress Callback to be called on download progress
	 * @param  callable $onFinish Callback to be called on download complete
	 */
	protected function downloadFile($url, $file, callable $onProgress, callable $onFinish)
	{
		$videoFile = fopen($file, 'a');
		$options = array(
			'sink' => $videoFile,
			'verify' => false,
			'timeout' => 0,
			'connect_timeout' => 50,
			'progress' => $onProgress,
			'cookies' => new CookieJar(false, [['url' => 'https://www.youtube.com/watch?v=' . $this->videoId]])
		);

		$request = new Request('get', $url);
		$promise = $this->webClient->sendAsync($request, $options);
		$promise->then(
			function (ResponseInterface $response) use ($videoFile, $file, $onFinish) {
				fclose($videoFile);

				$size = filesize($file);
				$remained = intval((string)$response->getHeader('Content-Length')[0]);

				$onFinish($size, $remained);
			},
			function (GuzzleException $e) {
				if ($e instanceof ClientException && $e->hasResponse())
					throw new YoutubeException($e->getResponse()->getReasonPhrase(), 4);
				else
					throw new YoutubeException($e->getMessage(), 4);
			}
		);

		try {
			$promise->wait();
		} catch (\RuntimeException $e) {
			throw new YoutubeException('An error occurred when downloading video', 20, $e);
		}
	}

	/**
	 * Downloads video or playlist videos by given itag
	 *
	 * @throws YoutubeException If Video ID is wrong or video not exists anymore or it's not viewable anyhow
	 *
	 * @param  int  $itag   After calling {@see getVideoInfo()}, it returns various formats, each format has it's own itag. if no itag is passed or passed itag is not valid for the video, it will download the best quality of video
	 * @param  boolean $resume If it should resume download if an uncompleted file exists or should download from beginning
	 * @param  mixed $caption Caption language to download or null to download caption of default language. false to prevent download
	 */
	public function download($itag=null, $resume=false, $caption=false)
	{
		if ($itag === null && $this->defaultItag !== null) $itag = $this->defaultItag;
		if (!$this->isPlaylist) {
			try {
				$this->getVideoInfo(true);
			} catch (YoutubeException $e) {
				throw $e;
			}

			$this->downloadVideo($itag, $resume, $caption);
		} else {
			$i = 1;
			foreach ($this->playlistInfo->video as $video)
			{
				$this->currentDownloadingVideoIndex = $i++;
				$this->videoId = $video->encrypted_id;
				try {
					$this->getVideoInfo(true);
				} catch (YoutubeException $e) {
					throw $e;
				}

				$this->downloadVideo($itag, $resume, $caption);
			}
		}
	}

	/**
	 * Downloads selected video by given itag
	 *
	 * @throws YoutubeException If Video ID is wrong or video not exists anymore or it's not viewable anyhow
	 *
	 * @param  int  $itag   After calling {@see getVideoInfo()}, it returns various formats, each format has it's own itag. if no itag is passed or passed itag is not valid for the video, it will download the best quality of video
	 * @param  boolean $resume If it should resume download if an uncompleted file exists or should download from beginning
	 * @param  mixed $caption Caption language to download or null to download caption of default language. false to prevent download
	 */
	protected function downloadVideo($itag=null, $resume=false, $caption=false)
	{
		if ($itag) {
			foreach ($this->videoInfo->full_formats as $video) {
				if ($video->itag == $itag) {
					$this->downloadFull($video->url, $video->filename, $resume);
					$this->finalize($video->filename, $caption);
					return;
				}
			}

			foreach ($this->videoInfo->adaptive_formats as $video) {
				if ($video->itag == $itag) {
					$this->downloadAdaptive($video->url, $video->filename, $video->clen, $resume);
					$this->finalize($video->filename, $caption);
					return;
				}
			}
		}

		if (count($this->videoInfo->full_formats) === 0) {
			if (count($this->videoInfo->adaptive_formats) === 0)
				throw new YoutubeException('There is no format for download', 32);
			else {
				$video = $this->videoInfo->adaptive_formats[0];
				$this->downloadAdaptive($video->url, $video->filename, $video->clen, $resume);
				$this->finalize($video->filename, $caption);
			}
		}

		$video = $this->videoInfo->full_formats[0];
		$this->downloadFull($video->url, $video->filename, $resume);
		$this->finalize($video->filename, $caption);
	}

	/**
	 * Finalizes downloaded file
	 *
	 * @param  string  $filename   Full name of file to save to
	 * @param  mixed $caption Caption language to download or null to download caption of default language. false to prevent download
	 */
	public function finalize($filename, $caption)
	{
		$canEditFile = false;
		$filePath = $this->path . DIRECTORY_SEPARATOR . $filename;
		$file = null;

		if ($this->mp4EditingEnabled && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) == 'mp4') {
			$file = new Mp4($filePath);
			$canEditFile = true;

			if (strtolower($this->captionFormat) == 'sub')
				$this->defaultFPS = $file->getFPS();
		}

		if ($caption !== false && count($this->videoInfo->captions))
			$this->downloadCaption($this->videoInfo->captions, $caption, $filename);

		if ($canEditFile) {
			try {
				$response = $this->webClient->get($this->videoInfo->image['medium_quality']);
				if ($response->getStatusCode() == 200)
					$file->setCover($response->getBody());
			} catch (GuzzleException $e) {}

			$file->setTrackName($this->videoInfo->title);
			$file->setArtist($this->videoInfo->author);

			$videosCount = $this->isPlaylist ? count($this->playlistInfo->video) : 1;
			if ($videosCount > 1)
				$file->setTrackNumber($this->currentDownloadingVideoIndex, $videosCount);

			$file->setSoftwareInformation('Downloaded by YoutubeDownloader https://is.gd/Youtubedownloader');
			$file->setMediaType(MediaTypes::Movie);

			$tempFilePath = $filePath . time();
			copy($filePath, $tempFilePath); // Make backup of video in case something goes wrong

			try {
				$file->save();
				unlink($tempFilePath);
			} catch (\Zend_Media_Iso14496_Exception $e) {
				$file = null;
				rename($tempFilePath, $filePath); // Restore backup file in case of error
			}

			if ($file && $this->autoCloseEditedMp4Files) {
				$file->__destruct();
				$file = null;
			}
		}

		$videosCount = $this->isPlaylist ? count($this->playlistInfo->video) : 1;

		$onFinalized = $this->onFinalized;
		$onFinalized($filePath, filesize($filePath), $this->currentDownloadingVideoIndex, $videosCount);
	}

	/**
	 * Downloads full_formats videos given by {@see getVideoInfo()}
	 *
	 * @param  string  $url    Video url given by {@see getVideoInfo()}
	 * @param  string  $file   Path of file to save to
	 * @param  boolean $resume If it should resume download if an uncompleted file exists or should download from beginning
	 */
	public function downloadFull($url, $file, $resume=false)
	{
		$file = $this->path . DIRECTORY_SEPARATOR . $file;
		if (file_exists($file) && !$resume)
			unlink($file);

		$downloadedBytes = &$this->downloadedBytes;
		$fileSize = &$this->fileSize;
		$onProgress = &$this->onProgress;
		$onComplete = &$this->onComplete;
		$videosCount = $this->isPlaylist ? count($this->playlistInfo->video) : 1;

		$this->downloadFile(
			$url, $file,
			function ($downloadSize, $downloaded) use (&$downloadedBytes, &$fileSize, $onProgress, $videosCount) {
				if (!$downloaded && !$downloadSize) return 1;
				if ($downloadedBytes != $downloaded)
					$onProgress($downloaded, $downloadSize, $this->currentDownloadingVideoIndex, $videosCount);

				$downloadedBytes = $downloaded;
				$fileSize = $downloadSize;
				return 0;
			},
			function ($downloadSize) use ($onComplete, $file, $videosCount) {
				$onComplete($file, $downloadSize, $this->currentDownloadingVideoIndex, $videosCount);
			}
		);
	}

	/**
	 * Downloads adaptive_formats videos given by {@see getVideoInfo()}. in adaptive formats, video and voice are separated.
	 *
	 * @param  string  $url           Resource url given by {@see getVideoInfo()}
	 * @param  string  $file          Path of file to save to
	 * @param  integer $completeSize  Completed file size
	 * @param  boolean $resume        If it should resume download if an uncompleted file exists or should download from beginning
	 */
	public function downloadAdaptive($url, $file, $completeSize, $resume=false)
	{
		$file = $this->path . DIRECTORY_SEPARATOR . $file;

		$size = 0;
		if (file_exists($file))
		{
			if ($resume)
				$size += filesize($file);
			else
				unlink($file);
		}

		$downloadedBytes = &$this->downloadedBytes;
		$fileSize = &$this->fileSize;
		$onProgress = &$this->onProgress;
		$onComplete = &$this->onComplete;
		$videosCount = $this->isPlaylist ? count($this->playlistInfo->video) : 1;

		while ($size < $completeSize)
		{
			$this->downloadFile(
				$url . '&range=' . $size . '-' . $completeSize, $file,
				function ($downloadSize, $downloaded) use (&$downloadedBytes, &$fileSize, $onProgress, $videosCount)  {
					if (!$downloaded && !$downloadSize) return 1;
					if ($downloadedBytes != $downloaded)
						$onProgress($downloaded, $downloadSize, $this->currentDownloadingVideoIndex, $videosCount);

					$downloadedBytes = $downloaded;
					$fileSize = $downloadSize;

					return 0;
				},
				function ($downloadSize) use (&$size) {
					$size += $downloadSize;
				}
			);

			// Maybe we need to refresh download link each time
		}

		$onComplete($file, $size, $this->currentDownloadingVideoIndex, $videosCount);
	}

	/**
	 * Sets downloaded videos path
	 *
	 * @param  string $path Path to save videos (without ending slash)
	 */
	public function setPath($path)
	{
		$this->path = $path;
	}

	/**
	 * Sets default itag
	 *
	 * @param  integer|string $itag Default itag number if user not specified any
	 */
	public function setDefaultItag($itag)
	{
		$this->defaultItag = (string)$itag;
	}

	/**
	 * Sets default caption language
	 *
	 * @param  string $language Default caption language in 2 letters format (e.g. "en")
	 */
	public function setDefaultCaptionLanguage($language)
	{
		$this->defaultCaptionLanguage = (string)$language;
	}

	/**
	 * Sets default caption format
	 *
	 * @param  string $format Caption format, including "srt" (default), "sub", "ass"
	 */
	public function setCaptionFormat($format)
	{
		if (in_array($format, array('srt', 'sub', 'ass')))
			$this->captionFormat = (string)$format;
	}

	/**
	 * Sets default caption format
	 *
	 * @param  boolean $enable Enables or disables use of mp4 editing
	 * @param  boolean $autoClose Auto close edited Mp4 file (Underlying package has a bug. Use at your own risk)
	 */
	public function enableMp4Editing($enable, $autoClose=false)
	{
		$this->mp4EditingEnabled = !!$enable;
		$this->autoCloseEditedMp4Files = !!$autoClose;
	}

	/**
	 * Sets downloaded videos path
	 *
	 * @param  float $time Time in float
	 * @return array           Time array [hours, minutes, seconds]
	 */
	protected function parseFloatTime($time)
	{
		$result = array();
		array_push($result, fmod($time, 60));
		$time = intval($time / 60);
		array_push($result, fmod($time, 60));
		$time = intval($time / 60);
		array_push($result, fmod($time, 60));
		return array_reverse($result);
	}
}
