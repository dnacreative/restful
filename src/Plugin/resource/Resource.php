<?php

/**
 * @file
 * Contains \Drupal\restful\Plugin\resource\Resource.
 */

namespace Drupal\restful\Plugin\resource;

use Drupal\Component\Plugin\PluginBase;
use Drupal\restful\Authentication\AuthenticationManager;
use Drupal\restful\Authentication\AuthenticationManagerInterface;
use Drupal\restful\Exception\BadRequestException;
use Drupal\restful\Exception\ForbiddenException;
use Drupal\restful\Exception\GoneException;
use Drupal\restful\Exception\NotImplementedException;
use Drupal\restful\Exception\ServerConfigurationException;
use Drupal\restful\Exception\UnauthorizedException;
use Drupal\restful\Http\HttpHeader;
use Drupal\restful\Http\RequestInterface;
use Drupal\restful\Plugin\ConfigurablePluginTrait;
use Drupal\restful\Plugin\resource\DataProvider\DataProviderInterface;
use Drupal\restful\Plugin\resource\Field\ResourceFieldCollection;
use Drupal\restful\Plugin\resource\Field\ResourceFieldCollectionInterface;
use Drupal\restful\Resource\ResourceManager;

abstract class Resource extends PluginBase implements ResourceInterface {

  use ConfigurablePluginTrait;

  /**
   * The requested path.
   *
   * @var string
   */
  protected $path;

  /**
   * The current request.
   *
   * @var RequestInterface
   */
  protected $request;

  /**
   * The data provider.
   *
   * @var DataProviderInterface
   */
  protected $dataProvider;

  /**
   * The field definition object.
   *
   * @var ResourceFieldCollectionInterface
   */
  protected $fieldDefinitions;

  /**
   * The authentication manager.
   *
   * @var AuthenticationManagerInterface
   */
  protected $authenticationManager;

  /**
   * Indicates if the resource is enabled.
   *
   * @var bool
   */
  protected $enabled = TRUE;

  /**
   * Constructs a Drupal\Component\Plugin\PluginBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->fieldDefinitions = ResourceFieldCollection::factory($this->processPublicFields($this->publicFields()));

    $this->initAuthenticationManager();
  }

  /**
   * {@inheritdoc}
   */
  public function getAccount($cache = TRUE) {
    return $this->authenticationManager->getAccount($this->getRequest());
  }

  /**
   * {@inheritdoc}
   */
  public function getRequest() {
    if (isset($this->request)) {
      return $this->request;
    }
    $instance_configuration = $this->getConfiguration();
    if (!$this->request = $instance_configuration['request']) {
      throw new ServerConfigurationException('Request object is not available for the Resource plugin.');
    }
    return $this->request;
  }

  /**
   * {@inheritdoc}
   */
  public function setRequest(RequestInterface $request) {
    $this->request = $request;
  }

  /**
   * {@inheritdoc}
   */
  public function getPath() {
    return $this->path;
  }

  /**
   * {@inheritdoc}
   */
  public function setPath($path) {
    $this->path = $path;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldDefinitions() {
    return $this->fieldDefinitions;
  }

  /**
   * {@inheritdoc}
   */
  public function setFieldDefinitions(ResourceFieldCollectionInterface $field_definitions) {
    $this->fieldDefinitions = $field_definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getDataProvider() {
    if (isset($this->dataProvider)) {
      return $this->dataProvider;
    }
    return $this->dataProviderFactory();
  }

  /**
   * {@inheritdoc}
   */
  public function getResourceName() {
    $definition = $this->getPluginDefinition();
    return $definition['name'];
  }

  /**
   * {@inheritdoc}
   */
  public function getResourceMachineName() {
    $definition = $this->getPluginDefinition();
    return $definition['resource'];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array(
      'request' => restful()->getRequest(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process() {
    $path = $this->getPath();

    return ResourceManager::executeCallback($this->getControllerFromPath($path), array($path));
  }

  /**
   * {@inheritdoc}
   */
  public function controllersInfo() {
    return array(
      '' => array(
        // GET returns a list of entities.
        RequestInterface::METHOD_GET => 'index',
        RequestInterface::METHOD_HEAD => 'index',
        // POST.
        RequestInterface::METHOD_POST => 'create',
      ),
      // We don't know what the ID looks like, assume that everything is the ID.
      '^.*$' => array(
        RequestInterface::METHOD_GET => 'view',
        RequestInterface::METHOD_HEAD => 'view',
        RequestInterface::METHOD_PUT => 'replace',
        RequestInterface::METHOD_PATCH => 'update',
        RequestInterface::METHOD_DELETE => 'remove',
      ),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getControllers() {
    $controllers = array();
    foreach ($this->controllersInfo() as $path => $method_info) {
      $controllers[$path] = array();
      foreach ($method_info as $http_method => $controller_info) {
        $controllers[$path][$http_method] = $controller_info;
        if (!is_array($controller_info)) {
          $controllers[$path][$http_method] = array('callback' => $controller_info);
        }
      }
    }

    return $controllers;
  }

  /**
   * {@inheritdoc}
   */
  public function index($path) {
    return $this->getDataProvider()->index();
  }

  /**
   * {@inheritdoc}
   */
  public function view($path) {
    // TODO: Compare this with 1.x logic.
    $ids = explode(static::IDS_SEPARATOR, $path);

    // REST requires a canonical URL for every resource.
    $canonical_path = $this->getDataProvider()->canonicalPath($path);
    $this
      ->getRequest()
      ->getHeaders()
      ->add(HttpHeader::create('Link', $this->versionedUrl($canonical_path, array(), FALSE) . '; rel="canonical"'));

    return $this->getDataProvider()->viewMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function create($path) {
    // TODO: Compare this with 1.x logic.
    $object = $this->getRequest()->getParsedBody();
    return $this->getDataProvider()->create($object);
  }

  /**
   * {@inheritdoc}
   */
  public function update($path) {
    // TODO: Compare this with 1.x logic.
    $object = $this->getRequest()->getParsedBody();
    return $this->getDataProvider()->update($path, $object, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function replace($path) {
    // TODO: Compare this with 1.x logic.
    $object = $this->getRequest()->getParsedBody();
    return $this->getDataProvider()->update($path, $object, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function remove($path) {
    // TODO: Compare this with 1.x logic.
    $this->getDataProvider()->remove($path);
  }

  /**
   * {@inheritdoc}
   */
  public function getControllerFromPath($path = NULL, ResourceInterface $resource = NULL) {
    if (empty($resource)) {
      $resource = $this;
    }
    $path = $path ?: $resource->getPath();
    $method = $resource->getRequest()->getMethod();

    $selected_controller = NULL;
    foreach ($resource->getControllers() as $pattern => $controllers) {
      // Find the controllers for the provided path.
      if ($pattern != $path && !($pattern && preg_match('/' . $pattern . '/', $path))) {
        continue;
      }

      if ($controllers === FALSE) {
        // Method isn't valid anymore, due to a deprecated API endpoint.
        $params = array('@path' => $path);
        throw new GoneException(format_string('The path @path endpoint is not valid.', $params));
      }

      if (!isset($controllers[$method])) {
        $params = array('@method' => strtoupper($method));
        throw new BadRequestException(format_string('The http method @method is not allowed for this path.', $params));
      }

      // We found the controller, so we can break.
      $selected_controller = $controllers[$method];
      if (is_array($selected_controller)) {
        // If there is a custom access method for this endpoint check it.
        if (!empty($selected_controller['access callback']) && !ResourceManager::executeCallback(array($resource, $selected_controller['access callback']), array($path))) {
          throw new ForbiddenException(sprintf('You do not have access to this endpoint: %s - %s', $method, $path));
        }
        $selected_controller = $selected_controller['callback'];
      }

      // Create the callable from the method string.
      if (!ResourceManager::isValidCallback($selected_controller)) {
        // This means that the provided value means to be a public method on the
        // current class.
        $selected_controller = array($resource, $selected_controller);
      }
      break;
    }

    if (empty($selected_controller)) {
      throw new NotImplementedException(sprintf('There is no handler for "%s" on the path: %s', $resource->getRequest()->getMethod(), $path));
    }

    return $selected_controller;
  }

  /**
   * {@inheritdoc}
   */
  public function getVersion() {
    $plugin_definition = $this->getPluginDefinition();
    $version = array(
      'major' => $plugin_definition['majorVersion'],
      'minor' => $plugin_definition['minorVersion'],
    );
    return $version;
  }

  /**
   * {@inheritdoc}
   */
  public function versionedUrl($path = '', $options = array(), $version_string = TRUE) {
    // Make the URL absolute by default.
    $options += array('absolute' => TRUE);
    $plugin_definition = $this->getPluginDefinition();
    if (!empty($plugin_definition['menuItem'])) {
      $url = $plugin_definition['menuItem'] . '/' . $path;
      return url(rtrim($url, '/'), $options);
    }

    $base_path = variable_get('restful_hook_menu_base_path', 'api');
    $url = $base_path;
    if ($version_string) {
      $url .= '/v' . $plugin_definition['majorVersion'] . '.' . $plugin_definition['minorVersion'];
    }
    $url .= '/' . $plugin_definition['resource'] . '/' . $path;
    return url(rtrim($url, '/'), $options);
  }

  /**
   * {@inheritdoc}
   */
  public function access() {
    return $this->accessByAllowOrigin();
  }

  /**
   * {@inheritdoc}
   */
  public function enable() {
    $this->enabled = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function disable() {
    $this->enabled = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled() {
    return $this->enabled;
  }

  /**
   * Checks access based on the referer header and the allow_origin setting.
   *
   * @return bool
   *   TRUE if the access is granted. FALSE otherwise.
   */
  protected function accessByAllowOrigin() {
    // Check the referrer header and return false if it does not match the
    // Access-Control-Allow-Origin
    $referer = $this->getRequest()->getHeaders()->get('Referer')->getValueString();

    // If there is no allow_origin assume that it is allowed. Also, if there is
    // no referer then grant access since the request probably was not
    // originated from a browser.
    $plugin_definition = $this->getPluginDefinition();
    $origin = isset($plugin_definition['allowOrigin']) ? $plugin_definition['allowOrigin'] : NULL;
    if (empty($origin) || $origin == '*' || !$referer) {
      return TRUE;
    }
    return strpos($referer, $origin) === 0;
  }

  /**
   * Public fields.
   *
   * @return array
   *   The field definition array.
   */
  abstract protected function publicFields();

  /**
   * Get the public fields with the default values applied to them.
   *
   * @param array $field_definitions
   *   The field definitions to process.
   *
   * @return array
   *   The field definition array.
   */
  protected function processPublicFields(array $field_definitions) {
    // By default do not do any special processing.
    return $field_definitions;
  }

  /**
   * Initializes the authentication manager and adds the appropriate providers.
   *
   * This will return an AuthenticationManagerInterface if the current resource
   * needs to be authenticated. To skip authentication completely do not set
   * authenticationTypes and set authenticationOptional to TRUE.
   */
  protected function initAuthenticationManager() {
    $this->authenticationManager = new AuthenticationManager();

    $plugin_definition = $this->getPluginDefinition();
    $authentication_types = $plugin_definition['authenticationTypes'];
    $authentication_optional = $plugin_definition['authenticationOptional'];
    $this->authenticationManager->setIsOptional($authentication_optional);
    if (empty($authentication_types)) {
      if (empty($authentication_optional)) {
        // Fail early, fail good.
        throw new UnauthorizedException('There are no authentication providers and authentication is not optional.');
      }
      return;
    }
    if ($authentication_types === TRUE) {
      // Add all the available authentication providers to the manager.
      $this->authenticationManager->addAllAuthenticationProviders();
      return;
    }
    foreach ($authentication_types as $authentication_type) {
      // Read the authentication providers and add them to the manager.
      $this->authenticationManager->addAuthenticationProvider($authentication_type);
    }
  }

}
