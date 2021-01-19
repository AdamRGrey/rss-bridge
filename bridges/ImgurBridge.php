<?php
class ImgurBridge extends BridgeAbstract {
	const NAME = 'Imgur Bridge';
	const URI = 'https://imgur.com/';
	const DESCRIPTION = 'get imgur stuff with API an key';
	const MAINTAINER = '@AdamRGrey';
	const PARAMETERS = array(
		'User' => array(
			'u' => array(
				'name' => 'Username',
				'exampleValue' => 'Sarah',
				'title' => 'only ascii letters, numbers, underscores, dashes',
				'pattern' => '[a-zA-Z0-9-_]+',
				'required' => true
			)
		),
		'Tag' => array(
			't' => array(
				'name' => 'tag name',
				'exampleValue' => 'movies_and_tv',
				'title' => 'only ascii letters, numbers, underscores, dashes',
				'pattern' => '[a-zA-Z0-9-_]+',
				'required' => true
			)
		),
		'Gallery' => array(
			'section' => array(
				'name' => 'section',
				'type' => 'list',
				'required' => true,
				'values' => array(
					'Hot' => 'hot',
					'Top' => 'top',
					'User' => 'user'
				),
				'defaultValue' => 'hot'
			),
			'sort' => array(
				'name' => 'sort',
				'type' => 'list',
				'required' => true,
				'values' => array(
					'Viral' => 'viral',
					'Top' => 'top',
					'Time' => 'time',
					'Rising' => 'rising'
				),
				'defaultValue' => 'viral'
			),
			'window' => array(
				'name' => 'window',
				'type' => 'list',
				'required' => true,
				'values' => array(
					'Day' => 'day',
					'Week' => 'week',
					'Month' => 'month',
					'Year' => 'year',
					'All' => 'all'
				),
				'defaultValue' => 'day'
			)
		),
		'Leaderboard' => array(),
	);
	const CONFIGURATION = array(
		'api_key' => array(
			'required' => true,
		)
	);
	
	public function getName() {

		switch($this->queriedContext) {

			case 'User':

				return $this->getInput('u');

				break;

			case 'Tag':

				return 'tag: ' . $this->getInput('t');

				break;

			case 'Gallery':

				return 'gallery: '
					. $this->getInput('section')
					. '/'
					. $this->getInput('sort')
					. '/'
					. $this->getInput('window');

				break;

			case 'Leaderboard':
				return 'Leaderboard';

				break;
		}

		return parent::getName();

	}

	public function collectData() {

		$url = 'https://api.imgur.com/3/';
		
		switch($this->queriedContext) {

			case 'User':

				$url .= 'account/'
					. $this->getInput('u')
					. '/submissions/0/newest';

				$response = $this->simpleGetFromJson($url);

				$this->itemizeData($response->data);

				break;

			case 'Tag':
				$url .= 'gallery/t/' . $this->getInput('t');

				$response = $this->simpleGetFromJson($url);

				$this->itemizeData($response->data->items);

				break;

			case 'Gallery':

				$url .= 'gallery/'
					. $this->getInput('section')
					. '/'
					. $this->getInput('sort')
					. '/'
					. $this->getInput('window');

				$response = $this->simpleGetFromJson($url);

				$this->itemizeData($response->data);

				break;

			case 'Leaderboard':

				$this->itemizeTopComments();

				break;

			default:
				returnClientError(
					'Unknown context: "'
					. $this->queriedContext 
					. '"!'
				);

		}

	}

	private function itemizeData($data){

		foreach ($data as $album) {
			$item = array();

			$item['uri'] = $album->link;
			$item['title'] = $album->title;
			$item['timestamp'] = $album->datetime;
			$item['author'] = $album->account_url;
			$item['content'] = '';
			$item['categories'] = array();

			foreach ($album->tags as $tag) {
				$item['categories'][] = $tag->name;
			}

			if(property_exists($album, 'images')) {
				$imgs = $album->images;
				if($album->images_count > 3){
					$url = 'https://api.imgur.com/3/album/'
						. $album->id
						. '/images';
					$imgs = $this->simpleGetFromJson($url)->data;
				}
				foreach ($imgs as $image) {
					$item['content'] .= $this->albumImage2Html($image);
				}
			}elseif(property_exists($album, 'link')) {
				//the album is only 1 image
				$item['content'] .= $this->albumImage2Html($album);
			}

			$this->items[] = $item;
		}
	}

	private function withOrdinal($number) {
		//from https://stackoverflow.com/a/3110033/1173856
	    $ends = array('th','st','nd','rd','th','th','th','th','th','th');
	    if ((($number % 100) >= 11) && (($number%100) <= 13))
	        return $number . 'th';
	    else
	        return $number . $ends[$number % 10];
	}

	private function itemizeTopComments(){
		$url = 'https://api.imgur.com/comment/v1/comments/top?client_id='
			. $this->getOption('api_key');
		$response = $this->simpleGetFromJson($url);

		for ($i=0; $i < count($response->data); $i++) { 
			$comment = $response->data[$i];

			$item = array();

			$item['uri'] = 'https://imgur.com/gallery/'
				. $comment->post->id
				. '/comment/'
				. $comment->id;
			$item['title'] = $this->withOrdinal($i + 1);
			$item['timestamp'] = $comment->created_at;
			$item['author'] = $comment->account->username;
			$item['content'] = $comment->comment;

			$this->items[] = $item;
		}
	}

	private function albumImage2Html($img){
		$txt = '';
		if(substr($img->type, 0, 6) !== 'image/') {
			$txt .= '<video src="'
				. $img->link 
				. '" controls></video><br />';
		}else{
			$txt .= '<img src="' . $img->link . '" /><br />';
		}
		if(null !== $img->description && trim($img->description) !== '') {
			$desc = str_replace("\n", '<br />', $img->description);
			$txt .= $desc . '<br />';
		}
		return $txt;
	}

	private function simpleGetFromJson($url){
		$context = stream_context_create([
			'http' => [
				'method' => 'GET',
				'header' => ['Authorization: Client-ID '
					. $this->getOption('api_key')
				]
			]
		]);
		$result = file_get_contents($url, false, $context);
		return json_decode($result, false);
	}

	private function paramIsValid($txt) {
		return preg_match('/[^a-zA-Z_]/', $txt) === 0;
	}
}
