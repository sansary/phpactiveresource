<?php

namespace ActiveResource;

require_once 'vendor/autoload.php';
use Guzzle\Http\Client;
use Guzzle\Http\Exception;
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
	var $site = false;

	/**
	 * The remote collection, e.g., person or thing
	 */
	var $element_name = false;

	/**
	 * Pleural form of the element name, e.g., people or things
	 */
	var $element_name_plural = '';

	/**
	 * Corrections to improper pleuralizations.
	 */
	var $pleural_corrections = array('persons' => 'people', 'peoples' => 'people', 'mans' => 'men', 'mens' => 'men', 'womans' => 'women', 'womens' => 'women', 'childs' => 'children', 'childrens' => 'children', 'sheeps' => 'sheep', 'octopuses' => 'octopi', 'quizs' => 'quizzes', 'axises' => 'axes', 'buffalos' => 'buffaloes', 'tomatos' => 'tomatoes', 'potatos' => 'potatoes', 'oxes' => 'oxen', 'mouses' => 'mice', 'matrixes' => 'matrices', 'vertexes' => 'vertices', 'indexes' => 'indices', );

	
	
	var $data=false;
	var $scope=false;
    
    //---- in the init
	var $client;
	var $log;

	
	public function __construct($data=false, $scope=false){
		if ($data){
			$this->data = $data;
		}
		
		if($scope)
		{
			$this -> scope = $scope;
			foreach($scope as $k => $v)
			{
				$this->site = $this->site.$k.'/'.$v.'/';
			}
		}
        
        $this->element_name = $this->element_name ? $this->element_name : strtolower (get_class ($this));
        
        // Detect for namespaces, and take just the class name
		if (stripos ($this->element_name, '\\')) {
			$classItems = explode ('\\', $this->element_name);
			$this->element_name = end ($classItems);
		}
        
        // Get the plural name after removing namespaces
		$this->element_name_plural = $this->pluralize ($this->element_name);
        
        
        $this->client = new Client();
        $this->init_log();
	
  

	}	
		
	
	
	/**
	 * Pluralize the element name.
	 */
    function pluralize($word) {
		$word .= 's';
		$word = preg_replace('/(x|ch|sh|ss])s$/', '\1es', $word);
		$word = preg_replace('/ss$/', 'ses', $word);
		$word = preg_replace('/([ti])ums$/', '\1a', $word);
		$word = preg_replace('/sises$/', 'ses', $word);
		$word = preg_replace('/([^aeiouy]|qu)ys$/', '\1ies', $word);
		$word = preg_replace('/(?:([^f])fe|([lr])f)s$/', '\1\2ves', $word);
		$word = preg_replace('/ieses$/', 'ies', $word);
		if (isset($this -> $pleural_corrections[$word])) {
			return $this -> $pleural_corrections[$word];
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
		if (isset($this -> data['id'])) {
			return $this -> _send_and_receive($this -> site . $this -> element_name_plural.'/'.$this -> data['id']. '.json', 'PUT', $this -> data);
			// update
		}
		// this is a create
		return $this -> _send_and_receive($this -> site . $this -> element_name_plural . '.json', 'POST',  $this -> data);
		// create
	}

	/**
	 * Creates a new record
	 *
	 *     POST /collection.json

	 */
	function update($id, $data) {
		// this is a create
		if (isset($this -> data['id'])) {
			$id = $this -> data['id'];
		}
		return $this -> _send_and_receive($this -> site . $this -> element_name_plural.'/'.$id. '.json', 'PUT',  $data);
	}


	/**
	 * Creates a new record
	 *
	 *     POST /collection.json
	 */
	function create($data) {
		// this is a create
		return $this -> _send_and_receive($this -> site . $this -> element_name_plural . '.json', 'POST',  $data);
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
	function find($id, $options = array ()) {
		$options_string = '';
		if (count($options) > 0) {
			$options_string = '?' . http_build_query($options);
		}
		if ($id == 'all') {
            
			$url = $this -> site . $this -> element_name_plural . '.json';
			return $this -> _send_and_receive($url . $options_string, 'GET');
		}
		return $this -> _send_and_receive($this -> site . $this -> element_name_plural . '/' . $id . '.json' . $options_string, 'GET');
	}

	/**
	 * Finds a record or records via:
	 *
	 *     GET /collection.json
	 */
	function findall($options = array ()) {
		return $this -> find('all', $options);
	}

	/**
	 * Gets a specified custom method on the current object via:
	 *
	 *     GET /collection/id/action.json
	 *     GET /collection/id/action.json?attr=value 
	 */
	function get($id, $method, $options = array ()) {
		$options_string = '';
		if (count($options) > 0) {
			$options_string = '?' . http_build_query($options);
		}

		return $this -> _send_and_receive($this -> site . $this -> element_name_plural . '/' . $id .'/'.$method .'.json' . $options_string, 'GET');
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
		#return $this -> _send_and_receive($req, 'POST', $options, $start_tag);
		return $this -> _send_and_receive($this -> site . $this -> element_name_plural . '/' . $id .'/'.$method .'.json' . $options_string, 'POST');
		
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
	function _send_and_receive($url, $method, $data = array ()) {
		try {
		
		//1. Create the qreuest
		$request = null;
		$json = "";

		$post_headers = array('Content-Type'=> "application/json");

		switch ($method) {
			case 'POST' :
				$json = json_encode($data);
				$request = $this -> client -> post($url, $post_headers, $json);
				break;
			case 'DELETE' :
				//curl_setopt ($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
				break;
			case 'PUT' :
				$json = json_encode($data);
				$request = $this -> client -> put($url, $post_headers, $json);
				break;
			case 'GET' :
				$request = $this -> client -> get($url);
			default :
				break;
		}

		if ($this -> access_token) {
			$request -> addHeader('AUTHORIZATION', "Token token=" . $this -> access_token);
		}
		//2. add auth headers

		$this -> log -> addDebug($request -> getMethod() . " " . $request -> getURL() . "\n" . $json);
		
		
		//3. send the request
			
		
			
		$response = $request -> send();
		
		
		
		
		echo 'hereeeeeeee';
		//4. Decode the response and return
		$this -> log -> addDebug("\n" . $response -> getStatusCode() . " " . $response -> getReasonPhrase() . "\n" . $response -> getBody());
		
		$this -> log -> addDebug("------------------------------\n");
		$response_data = json_decode($response -> getBody());
	
		//if(is_array($response_data))
		//{
		//// if returns an array of objects
		//$res = array ();
		//	$cls = get_class ($this);
		//	foreach ($response_data as $child) {
		//		$obj = new $cls(false, $this->scope);
		//		foreach ((array) $child as $k => $v) {
		//			//$k = str_replace ('-', '_', $k);
		//			if (isset ($v['nil']) && $v['nil'] == 'true') {
		//				continue;
		//			} else {
		//				$obj->data[$k] = $v;
		//			}
		//		}
		//		//print_r($obj -> data);
		//		//$this -> log -> addDebug($obj -> data);
		//		$res[] = $obj;
		//	}
		////print_r($res);
		////var_dump($res);
		//return $res;
		//}
		//
		//else{
		//	// if returns just one object
		//		$cls = get_class ($this);
		//		$obj = new $cls(false, $this->scope);
		//		foreach ((array) $response_data as $k => $v) {
		//			//$k = str_replace ('-', '_', $k);
		//			if (isset ($v['nil']) && $v['nil'] == 'true') {
		//				continue;
		//			} else {
		//				$obj->data[$k] = $v;
		//			}
		//		}
		//	$this -> log -> addDebug($obj -> data);
		//	return $obj;
		//}
		
		return $response_data;
		
		}catch (\Exception $e) {
			$this -> log -> addDebug("\n Exception " . $e->getResponse() -> getBody());	 
		}

	}
	function init_log() {
		date_default_timezone_set('Europe/Stockholm');
		$this -> log = new Logger('ActiveResource');
		//$this->log->pushHandler(new StreamHandler('ActiveResource.log', Logger::DEBUG));
	}

		/**
	 * Getter for internal object data.
	 */
	function __get ($k) {
		if (isset ($this->data[$k])) {
			return $this->data[$k];
		}
		return $this->{$k};
	}

	/**
	 * Setter for internal object data.
	 */
	function __set ($k, $v) {
		if (isset ($this->data[$k])) {
			$this->data[$k] = $v;
			return;
		}
		$this->{$k} = $v;
	}

	/**
	 * Quick setter for chaining methods.
	 */
	function set ($k, $v = false) {
		if (! $v && is_array ($k)) {
			foreach ($k as $key => $value) {
				$this->data[$key] = $value;
			}
		} else {
			$this->data[$k] = $v;
		}
		return $this;
	}
	
	
	

}


class User extends ActiveResource{
    var $element_name = 'user';
    var $site = 'http://localhost:3000/';///accounts/2/';
    //$access_token = "eba5bef1b170df3f300f724d168ca1a1";
    var $access_token = "818a5f867d0a31ed6e2575b9d359a1a5";
}

//
//class Track extends ActiveResource {
//    var $element_name = 'track';
//    var $site = 'http://localhost:3000';
//    //$access_token = "eba5bef1b170df3f300f724d168ca1a1";
//    var $access_token = "c5833f3f9668296ee596bfa24c4416f3";
//}	
//
//
//

$a= new User(false, array("accounts" => 1));
$a -> create(array("api_user" => array("email" => "abc@email.com")));

//// account_admin can access account 2 details
//
// $a = new ApiUser(false, array("accounts" => 2));
// $user_4 = $a->find(4);
// var_dump($user_4 -> data);
// 
// $user_4 -> log -> addDebug($user_4 -> data);
// $user_4 -> email = 'new_email@skilab.com';
// $user_4 -> save();
// 
// // account_admin can't access account 1 details
//
// $a = new ApiUser(false, array("accounts" => 1));
// $user_4 = $a->find(2);
// var_dump($user_4 -> data);
// 
// // superadmin can't access account 2 details
// 
// $b = new ApiUser(false, array("accounts"=> 2));
// $b -> access_token = "79c173f2eb55ae717138211001ff6b04"; //super admin
// $user_4 = $b->find(4);
// 
// // superadmin can access account 1 details
// 
// $b = new ApiUser(false, array("accounts"=> 1));
// $b -> access_token = "79c173f2eb55ae717138211001ff6b04"; //super admin
// $user_4 = $b->find(2);
// 
// 
 // Testing api_users //
 
 // admin //
 
 
 // can create new account_admin
 
// $b = new ApiUser(false, array("accounts"=> 1));
// $b -> access_token = "79c173f2eb55ae717138211001ff6b04"; //super admin
// $user_4 = $b-> create(array("email" => "account_admin_new@skilab.com"));
//
//// can't change role
// $user_4 -> access_token = "79c173f2eb55ae717138211001ff6b04";
// $user_4 -> role_id = 1;
// $user_4 -> save();
 //
 
 // testing validation errors. // gives 422 email cant be blank
 
//  $b = new ApiUser(false, array("accounts"=> 1));
//  $b -> access_token = "79c173f2eb55ae717138211001ff6b04"; //super admin
//  $user_4 = $b-> create(array("email" => ""));


 
 // I think we should have one class for each role (super, account, user) and that's it
 // The rest is controlled with scope.
 
 // if no scope, then in account's scope (from element name)
 // so we create a new instance and pass element_name and scope. 
 
 
// $t = new Track();
// $t -> log -> addDebug("----------Element Names-------------\n");
// $a -> log -> addDebug("ApiUser: " . $a -> element_name . "\n");
// $t -> log -> addDebug("Track: " . $t -> element_name . "\n");
// $t -> log -> addDebug("------------------------------\n");
 
 
 
 // Get all api_users
// $all = $a -> findall();
 //echo 'all is' . $all[0] ;
// print_r($all[1] -> data);
 
  // Get all tracks
// $t -> findall();
 
  // Get one api_user
// $b = $a -> find(4);
 
 //echo "B ISSSSSSSSS " . $b -> data['email'];
 // updating a value
// $b -> set ('email', 'user-00admin@skilab.com') -> save ();
 
// echo "B ISSSSSSSSS " . $b -> data['email'];
 
// $b->update(null, array('email' => 'adsfadaf@bla.com', 'role'=>2))
//echo ApiUser::$element_name;
//echo Track::$element_name;
//echo ApiUser::$element_name;
//
//echo ApiUser::$element_name_plural;
//echo Track::$element_name_plural;
//echo ApiUser::$element_name_plural;

//echo ApiUser::$element_name_plural;
//ApiUser::findall();
//$account_scope = array("accounts"=>2);
//$track_scope = array("accounts"=>2, 'tracks'=>1);
//$a = ApiUser::find('4', $account_scope);
//echo $a -> email . "\n";
//echo $a -> id . "\n";
//echo $a -> access_token . "\n";

//ApiUser::create(array('email' => 'foo@foo.com'));
//ApiUser::update(4, array('email' => 'adsfadaf@bla.com', 'role'=>2));
////Track::getTrackData();


?>
