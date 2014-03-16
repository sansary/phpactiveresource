<?php

namespace ActiveResource;

require_once 'vendor/autoload.php';
use Guzzle\Http\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Basic implementation of the Ruby on Rails ActiveResource REST client.
 * Intended to work with RoR-based REST servers, which all share similar
 * API patterns.
 *
 * Usage:
 *
 *     <?php
 *
 *     require_once ('ActiveResource.php');
 *
 *     class Song extends ActiveResource {
 *         var $site = 'http://localhost:3000/';
 *         var $element_name = 'songs';
 *     }
 *
 *     // create new item
 *     $song = new Song (array ('artist' => 'Joe Cocker', 'title' => 'A Little Help From My Friends'));
 *     $song->save ();
 *
 *     // fetch and update an item
 *     $song->find (44)->set ('title', 'The River')->save ();
 *
 *     // line by line
 *     $song->find (44);
 *     $song->title = 'The River';
 *     $song->save ();
 *
 *     // get all songs
 *     $songs = $song->find ('all');
 *
 *     // delete a song
 *     $song->find (44);
 *     $song->destroy ();
 *
 *     // custom method
 *     $songs = $song->get ('by_year', array ('year' => 1999));
 *
 *

 ?>
 *
 * @author John Luxford
 <lux@companymachine.com>
 * @version 0.14 beta
 * @license http://opensource.org/licenses/lgpl-2.1.php
 */
class ActiveResource {
	/**
	 * The REST site address, e.g., http://user:pass@domain:port/
	 */
	protected static $site = false;

	/**
	 * The remote collection, e.g., person or thing
	 */
	protected static $element_name = false;

	/**
	 * Pleural form of the element name, e.g., people or things
	 */
	public  static $element_name_plural = '';

	/**
	 * Corrections to improper pleuralizations.
	 */
	protected static $pleural_corrections = array('persons' => 'people', 'peoples' => 'people', 'mans' => 'men', 'mens' => 'men', 'womans' => 'women', 'womens' => 'women', 'childs' => 'children', 'childrens' => 'children', 'sheeps' => 'sheep', 'octopuses' => 'octopi', 'quizs' => 'quizzes', 'axises' => 'axes', 'buffalos' => 'buffaloes', 'tomatos' => 'tomatoes', 'potatos' => 'potatoes', 'oxes' => 'oxen', 'mouses' => 'mice', 'matrixes' => 'matrices', 'vertexes' => 'vertices', 'indexes' => 'indices', );

	
	
	private $data=false;
		
		
	public function __construct($data=false){
		if ($data){
			$this->data = $data;
		}
	}	
		
	
	//---- in the init
	protected static $client;
	protected static $log;
	

	public static function init() {

		static::$client = new Client();
		static::init_log();

		// Allow class-defined element name or use class name if not defined
		static::$element_name = static::$element_name ? static::$element_name : strtolower(get_called_class());
		
		// Detect for namespaces, and take just the class name
		if (stripos(static::$element_name, '\\')) {
			$classItems = explode('\\', static::$element_name);
			static::$element_name = end($classItems);
		}

		// Get the plural name after removing namespaces
		static::$element_name_plural = static::pluralize(static::$element_name);
	}

	/**
	 * Pluralize the element name.
	 */
	private static function pluralize($word) {
		$word .= 's';
		$word = preg_replace('/(x|ch|sh|ss])s$/', '\1es', $word);
		$word = preg_replace('/ss$/', 'ses', $word);
		$word = preg_replace('/([ti])ums$/', '\1a', $word);
		$word = preg_replace('/sises$/', 'ses', $word);
		$word = preg_replace('/([^aeiouy]|qu)ys$/', '\1ies', $word);
		$word = preg_replace('/(?:([^f])fe|([lr])f)s$/', '\1\2ves', $word);
		$word = preg_replace('/ieses$/', 'ies', $word);
		if (isset(static::$pleural_corrections[$word])) {
			return static::$pleural_corrections[$word];
		}
		return $word;
	}

	// /**
	// * For backwards-compatibility.
	// */
	// function pleuralize ($word) {
	// return $this->pluralize ($word);
	// }

	/**
	 * Saves a new record or upd	ates an existing one via:
	 *
	 *     POST /collection.json
	 *     PUT  /collection/id.json
	 */
	function save() {
		//this is an update
		if (isset($this -> _data['id'])) {
			return $this -> _send_and_receive($this -> site . $this -> element_name_plural . '/' . $this -> _data['id'] . '.json', 'PUT', $this -> _data);
			// update
		}
		// this is a create
		return $this -> _send_and_receive($this -> site . $this -> element_name_plural . '.json', 'POST', $this -> _data);
		// create
	}

	/**
	 * Creates a new record
	 *
	 *     POST /collection.json

	 */
	public static function update($id, $data) {
		// this is a create
		return static::_send_and_receive(static::$site . static::$element_name_plural.'/'.$id. '.json', 'PUT', array(static::$element_name => $data));
	}


	/**
	 * Creates a new record
	 *
	 *     POST /collection.json
	 */
	public static function create($data) {
		// this is a create
		return static::_send_and_receive(static::$site . static::$element_name_plural . '.json', 'POST', array(static::$element_name => $data));
	}





	/**
	 * Deletes a record via:
	 *
	 *     DELETE /collection/id.json
	 */
	function destroy() {
		return $this -> _send_and_receive($this -> site . $this -> element_name_plural . '/' . $this -> _data['id'] . '.json', 'DELETE');
	}

	/**
	 * Finds a record or records via:
	 *
	 *     GET /collection/id.json
	 *     GET /collection.json
	 */
	public static function find($id, $options = array ()) {
		$options_string = '';
		if (count($options) > 0) {
			$options_string = '?' . http_build_query($options);
		}
		if ($id == 'all') {
			$url = static::$site . static::$element_name_plural . '.json';
			return static::_send_and_receive($url . $options_string, 'GET');
		}
		return static::_send_and_receive(static::$site . static::$element_name_plural . '/' . $id . '.json' . $options_string, 'GET');
	}

	/**
	 * Finds a record or records via:
	 *
	 *     GET /collection.json
	 */
	public static function findall($options = array ()) {
		static::find('all', $options);
	}

	/**
	 * Gets a specified custom method on the current object via:
	 *
	 *     GET /collection/id/action.json
	 *     GET /collection/id/action.json?attr=value 
	 */
	public static function get($id, $method, $options = array ()) {
		$options_string = '';
		if (count($options) > 0) {
			$options_string = '?' . http_build_query($options);
		}

		return static::_send_and_receive(static::$site . static::$element_name_plural . '/' . $id .'/'.$method .'.json' . $options_string, 'GET');		
	}

	/**
	 * Posts to a specified custom method on the current object via:
	 *
	 *     POST /collection/id/action.json
	 */
	function post($method, $options = array ()) {
		$req = $this -> site . $this -> element_name_plural;
		if ($this -> _data['id']) {
			$req .= '/' . $this -> _data['id'];
		}
		$req .= '/' . $method . '.json';
		return $this -> _send_and_receive($req, 'POST', $options, $start_tag);
		return static::_send_and_receive(static::$site . static::$element_name_plural . '/' . $id .'/'.$method .'.json' . $options_string, 'GET');		
		
	}

	/**
	 * Puts to a specified custom method on the current object via:
	 *
	 *     PUT /collection/id/method.json
	 */
	function put($method, $options = array (), $options_as_xml = false, $start_tag = false) {
		$req = $this -> site . $this -> element_name_plural;
		if ($this -> _data['id']) {
			$req .= '/' . $this -> _data['id'];
		}
		$req .= '/' . $method . '.json';
		if ($options_as_xml) {
			return $this -> _send_and_receive($req, 'PUT', $options, $start_tag);
		}
		if (count($options) > 0) {
			$req .= '?' . http_build_query($options);
		}
		return $this -> _send_and_receive($req, 'PUT');
	}

	/**
	 * Build the request, call _fetch() and parse the results.
	 */
	private static function _send_and_receive($url, $method, $data = array ()) {

		//1. Create the qreuest
		$request = null;
		$json = "";

		$post_headers = array('Content-Type'=> "application/json");

		switch ($method) {
			case 'POST' :
				$json = json_encode($data);
				$request = static::$client -> post($url, $post_headers, $json);
				break;
			case 'DELETE' :
				//curl_setopt ($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
				break;
			case 'PUT' :
				$json = json_encode($data);
				$request = static::$client -> put($url, $post_headers, $json);
				break;
			case 'GET' :
				$request = static::$client -> get($url);
			default :
				break;
		}

		if (static::$access_token) {
			$request -> addHeader('AUTHORIZATION', "Token token=" . static::$access_token);
		}
		//2. add auth headers

		static::$log -> addDebug($request -> getMethod() . " " . $request -> getURL() . "\n" . $json);
		//3. send the request
		$response = $request -> send();

		//4. Decode the response and return
		static::$log -> addDebug("\n" . $response -> getStatusCode() . " " . $response -> getReasonPhrase() . "\n" . $response -> getBody());
		
		static::$log -> addDebug("------------------------------\n");		
		return json_decode($response -> getBody());

	}

	protected function init_log() {
		date_default_timezone_set('Europe/Stockholm');
		static::$log = new Logger('ActiveResource');
		//$this->log->pushHandler(new StreamHandler('ActiveResource.log', Logger::DEBUG));
	}

}


class ApiUser extends ActiveResource{
	public static $element_name = 'api_user';
	public static $site = 'http://localhost:3000/accounts/2/';
	public static $access_token = "eba5bef1b170df3f300f724d168ca1a1";	
}
ApiUser::init();

class Track extends ActiveResource {
	public static $element_name = 'track';
	public static $site = 'http://localhost:3000/accounts/2/';
	public static $access_token = "eba5bef1b170df3f300f724d168ca1a1";
}	

Track::init();







echo ApiUser::$element_name;
echo Track::$element_name;
echo ApiUser::$element_name;

echo ApiUser::$element_name_plural;
echo Track::$element_name_plural;
echo ApiUser::$element_name_plural;


ApiUser::findall();
$account_scope = array("accounts"=>2);
$track_scope = array("accounts"=>2, 'tracks'=>1);
$a = ApiUser::find('4', $account_scope);
echo $a -> email . "\n";
echo $a -> id . "\n";
echo $a -> access_token . "\n";

ApiUser::create(array('email' => 'foo@foo.com'));
ApiUser::update(4, array('email' => 'adsfadaf@bla.com', 'role'=>2));
//Track::getTrackData();


?>
