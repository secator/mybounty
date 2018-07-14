<?php
/**
 * MyBounty v0.0.0.1 (https://secator.com/)
 * Copyright 2018, secator
 * Licensed under MIT (http://en.wikipedia.org/wiki/MIT_License)
 */

class HackerOne {
	
	private $_endpoints = array(
		'current_user' => 'https://hackerone.com/current_user',
		'sign_in'      => 'https://hackerone.com/users/sign_in',
		'bugs'         => 'https://hackerone.com/bugs.json?sort_type=latest_activity&sort_direction=%s&limit=%d&page=%d',
		'report'       => 'https://hackerone.com/reports/%d.json',
		'reputation'   => 'https://hackerone.com/settings/reputation/log?page=%d',
	);
	
	private $_email = null;
	private $_password = null;
	private $_token = null;
	private $_tmp = null;
	private $_cookie = null;
	
	function __construct($email, $password) {
		$this->_email    = $email;
		$this->_password = $password;
		$this->_tmp      = sys_get_temp_dir();
		$this->_cookie   = $this->_tmp . '/' . md5($this->_email . $this->_password);
	}
	
	public function sign_in() {
		$post = array(
			'authenticity_token' => $this->_token,
			'user[email]'        => $this->_email,
			'user[password]'     => $this->_password,
			'user[remember_me]'  => 1,
		);
		
		$data = $this->curl($this->_endpoints['sign_in'], $post);
		return ($data['info']['http_code'] == 302);
	}
	
	public function current_user() {
		$data = $this->curl($this->_endpoints['current_user']);
		$data = @json_decode($data['page'], true);
		
		if (empty($data['csrf_token'])) {
			return false;
		}
		$this->_token = $data['csrf_token'];
		
		if (empty($data['signed_in?'])) {
			if (!$this->sign_in()) {
				return false;
			}
		}
		
		return $data;
	}
	
	public function bugs($sort_direction = '', $page = 1, $limit = 100) {
		$page = max($page, 1);
		$data = $this->curl(sprintf($this->_endpoints['bugs'], $sort_direction, $limit, $page));
		$data = @json_decode($data['page'], true);
		return $data;
	}
	
	public function report($id) {
		$data = $this->curl(sprintf($this->_endpoints['report'], (int)$id));
		if ($data['info']['http_code'] == 200) {
			return $data['page'];
		}
		return false;
	}
	
	public function reputation() {
	}
	
	private function curl($url, $post = array(), $header = array()) {
		$curl = curl_init($url);
		
//		curl_setopt($curl, CURLOPT_PROXY, '127.0.0.1:8888');
		
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
		
		if (!empty($this->_token)) {
			$header[] = 'X-CSRF-Token: ' . $this->_token;
		}
		if (!empty($header)) {
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
		}
		
		if (!empty($post)) {
			curl_setopt($curl, CURLOPT_POST, 1);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
		}
		
		curl_setopt($curl, CURLOPT_COOKIEJAR, $this->_cookie);
		curl_setopt($curl, CURLOPT_COOKIEFILE, $this->_cookie);
		
		curl_setopt($curl, CURLOPT_REFERER, 'https://hackerone.com/');
		curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2840.59 Safari/537.36 OPR/41.0.2353.46");
		
		$page = curl_exec($curl);
		$info = curl_getinfo($curl);
		
		curl_close($curl);
		
		return array('page' => $page, 'info' => $info);
	}
}
