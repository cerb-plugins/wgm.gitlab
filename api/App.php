<?php
class WgmGitLab_API {
	const OAUTH_ACCESS_TOKEN_PATH = "oauth/token";
	const OAUTH_AUTHENTICATE_PATH = "oauth/authorize";
	
	static $_instance = null;
	private $_oauth = null;
	private $_base_url = null;
	
	private function __construct() {
		if(false == ($credentials = DevblocksPlatform::getPluginSetting('wgm.gitlab','credentials',false,true,true)))
			return;
		
		if(!isset($credentials['consumer_key']) || !isset($credentials['consumer_secret']))
			return;
		
		$this->_oauth = DevblocksPlatform::services()->oauth($credentials['consumer_key'], $credentials['consumer_secret']);
	}
	
	/**
	 * @return WgmGitLab_API
	 */
	static public function getInstance() {
		if(null == self::$_instance) {
			self::$_instance = new WgmGitLab_API();
		}

		return self::$_instance;
	}
	
	public function setToken($token) {
		$this->_oauth->setTokens($token);
	}
	
	public function setBaseUrl($base_url) {
		$this->_base_url = rtrim($base_url,'/') . '/';
	}
	
	public function post($path, $params) {
		return $this->_fetch($path, 'POST', $params);
	}
	
	public function get($path) {
		return $this->_fetch($path, 'GET');
	}
	
	private function _fetch($path, $method = 'GET', $params = array()) {
		$url = $this->_base_url . ltrim($path, '/');
		return $this->_oauth->executeRequestWithToken($method, $url, $params, 'Bearer');
	}
};

if(class_exists('Extension_PageMenuItem')):
class WgmGitLab_SetupPluginsMenuItem extends Extension_PageMenuItem {
	const POINT = 'wgmgitlab.setup.menu.plugins.gitlab';
	
	function render() {
		$tpl = DevblocksPlatform::services()->template();
		$tpl->assign('extension', $this);
		$tpl->display('devblocks:wgm.gitlab::setup/menu_item.tpl');
	}
};
endif;

if(class_exists('Extension_PageSection')):
class WgmGitLab_SetupSection extends Extension_PageSection {
	const ID = 'wgmgitlab.setup.gitlab';
	
	function render() {
		$tpl = DevblocksPlatform::services()->template();

		$visit = CerberusApplication::getVisit();
		$visit->set(ChConfigurationPage::ID, 'gitlab');
		
		$credentials = DevblocksPlatform::getPluginSetting('wgm.gitlab','credentials',false,true,true);
		$tpl->assign('credentials', $credentials);
		
		$tpl->display('devblocks:wgm.gitlab::setup/index.tpl');
	}
	
	function saveJsonAction() {
		try {
			@$base_url = DevblocksPlatform::importGPC($_REQUEST['base_url'],'string','');
			@$consumer_key = DevblocksPlatform::importGPC($_REQUEST['consumer_key'],'string','');
			@$consumer_secret = DevblocksPlatform::importGPC($_REQUEST['consumer_secret'],'string','');
			
			// [TODO] Validate https, etc
			if(empty($base_url))
				$base_url = 'https://gitlab.com/';
			
			if(empty($consumer_key) || empty($consumer_secret))
				throw new Exception("Both the 'Client ID' and 'Client Secret' are required.");

			$credentials = [
				'base_url' => rtrim($base_url,'/') . '/',
				'consumer_key' => $consumer_key,
				'consumer_secret' => $consumer_secret,
			];
			
			DevblocksPlatform::setPluginSetting('wgm.gitlab', 'credentials', $credentials, true, true);
			
			echo json_encode(array('status'=>true, 'message'=>'Saved!'));
			return;
			
		} catch (Exception $e) {
			echo json_encode(array('status'=>false, 'error'=>$e->getMessage()));
			return;
		}
	}
	
};
endif;

class ServiceProvider_GitLab extends Extension_ServiceProvider implements IServiceProvider_OAuth, IServiceProvider_HttpRequestSigner {
	const ID = 'wgm.gitlab.service.provider';

	function renderConfigForm(Model_ConnectedAccount $account) {
		$tpl = DevblocksPlatform::services()->template();
		$active_worker = CerberusApplication::getActiveWorker();
		
		$tpl->assign('account', $account);
		
		$params = $account->decryptParams($active_worker);
		$tpl->assign('params', $params);
		
		$tpl->display('devblocks:wgm.gitlab::provider/gitlab.tpl');
	}
	
	function saveConfigForm(Model_ConnectedAccount $account, array &$params) {
		@$edit_params = DevblocksPlatform::importGPC($_POST['params'], 'array', array());
		
		$active_worker = CerberusApplication::getActiveWorker();
		$encrypt = DevblocksPlatform::services()->encryption();
		
		// Decrypt OAuth params
		if(isset($edit_params['params_json'])) {
			if(false == ($outh_params_json = $encrypt->decrypt($edit_params['params_json'])))
				return "The connected account authentication is invalid.";
				
			if(false == ($oauth_params = json_decode($outh_params_json, true)))
				return "The connected account authentication is malformed.";
			
			if(is_array($oauth_params))
			foreach($oauth_params as $k => $v)
				$params[$k] = $v;
		}
		
		return true;
	}
	
	private function _getAppKeys() {
		$credentials = DevblocksPlatform::getPluginSetting('wgm.gitlab','credentials',false,true,true);
		
		if(!isset($credentials['consumer_key']) || !isset($credentials['consumer_secret']))
			return false;
		
		return array(
			'base_url' => $credentials['base_url'],
			'key' => $credentials['consumer_key'],
			'secret' => $credentials['consumer_secret'],
		);
	}
	
	function oauthRender() {
		@$form_id = DevblocksPlatform::importGPC($_REQUEST['form_id'], 'string', '');
		
		// Store the $form_id in the session
		$_SESSION['oauth_form_id'] = $form_id;
		
		$url_writer = DevblocksPlatform::services()->url();
		
		// [TODO] Report about missing app keys
		if(false == ($app_keys = $this->_getAppKeys()))
			return false;
		
		$oauth = DevblocksPlatform::services()->oauth($app_keys['key'], $app_keys['secret']);
		
		// Persist the view_id in the session
		$_SESSION['oauth_state'] = CerberusApplication::generatePassword(24);
		
		// OAuth callback
		$redirect_url = $url_writer->write(sprintf('c=oauth&a=callback&ext=%s', ServiceProvider_GitLab::ID), true);

		$url = sprintf("%s?client_id=%s&redirect_uri=%s&state=%s&response_type=code", 
			$app_keys['base_url'] . WgmGitLab_API::OAUTH_AUTHENTICATE_PATH,
			$app_keys['key'],
			rawurlencode($redirect_url),
			$_SESSION['oauth_state']
		);
		
		header('Location: ' . $url);
	}
	
	function oauthCallback() {
		@$oauth_state = $_SESSION['oauth_state'];
		
		$form_id = $_SESSION['oauth_form_id'];
		unset($_SESSION['oauth_form_id']);
		
		@$code = DevblocksPlatform::importGPC($_REQUEST['code'], 'string', '');
		@$state = DevblocksPlatform::importGPC($_REQUEST['state'], 'string', '');
		@$error = DevblocksPlatform::importGPC($_REQUEST['error'], 'string', '');
		@$error_msg = DevblocksPlatform::importGPC($_REQUEST['error_description'], 'string', '');
		
		$active_worker = CerberusApplication::getActiveWorker();
		$url_writer = DevblocksPlatform::services()->url();
		$encrypt = DevblocksPlatform::services()->encryption();
		
		$redirect_url = $url_writer->write(sprintf('c=oauth&a=callback&ext=%s', ServiceProvider_GitLab::ID), true);
		
		if(false == ($app_keys = $this->_getAppKeys()))
			return false;
		
		// Compare $state
		if($_SESSION['oauth_state'] != $state)
			return false;
		
		$oauth = DevblocksPlatform::services()->oauth($app_keys['key'], $app_keys['secret']);
		$oauth->setTokens($code);
		
		$params = $oauth->getAccessToken($app_keys['base_url'] . WgmGitLab_API::OAUTH_ACCESS_TOKEN_PATH, array(
			'client_id' => $app_keys['key'],
			'client_secret' => $app_keys['secret'],
			'code' => $code,
			'grant_type' => 'authorization_code',
			'redirect_uri' => $redirect_url,
		));
		
		if(!is_array($params) || !isset($params['access_token'])) {
			return false;
		}
		
		$gitlab = WgmGitLab_API::getInstance();
		$gitlab->setBaseUrl($app_keys['base_url']);
		$gitlab->setToken($params['access_token']);
		
		// Load their profile
		
		$json = $gitlab->get('api/v4/user');
		
		// Die with error
		if(!is_array($json) || !isset($json['username']))
			return false;
		
		$params['username'] = $json['username'];
		
		// Output
		$tpl = DevblocksPlatform::services()->template();
		$tpl->assign('form_id', $form_id);
		$tpl->assign('label', $params['username']);
		$tpl->assign('params_json', $encrypt->encrypt(json_encode($params)));
		$tpl->display('devblocks:cerberusweb.core::internal/connected_account/oauth_callback.tpl');
	}
	
	function authenticateHttpRequest(Model_ConnectedAccount $account, &$ch, &$verb, &$url, &$body, &$headers) {
		$credentials = $account->decryptParams();
		
		if(
			!isset($credentials['access_token'])
		)
			return false;
		
		// Add a bearer token
		$headers[] = sprintf('Authorization: %s %s', $credentials['token_type'], $credentials['access_token']);
		
		return true;
	}
}
