<?php

/**
 * @file
 * Contains \Drupal\restful\Plugin\resource\DataProvider\CachedDataProvider.
 */

namespace Drupal\restful\Plugin\resource\DataProvider;

use Drupal\restful\Exception\NotImplementedException;
use Drupal\restful\Http\Request;

class CachedDataProvider implements DataProviderInterface {

  /**
   * The decorated object.
   *
   * @var DataProviderInterface
   */
  protected $subject;

  /**
   * The cache controller to interact with the cache backed.
   *
   * @var \DrupalCacheInterface
   */
  protected $cacheController;

  /**
   * Constructs a CachedDataProvider object.
   *
   * @param DataProviderInterface $subject
   *   The data provider to add caching to.
   * @param \DrupalCacheInterface $cache_controller
   *   The cache controller to add the cache.
   */
  public function __construct(DataProviderInterface $subject, \DrupalCacheInterface $cache_controller) {
    $this->subject = $subject;
    $this->cacheController = $cache_controller;
  }

  /**
   * {@inheritdoc}
   */
  public function getRange() {
    return $this->subject->getRange();
  }

  /**
   * {@inheritdoc}
   */
  public function getAccount() {
    return $this->subject->getAccount();
  }

  /**
   * {@inheritdoc}
   */
  public function getRequest() {
    return $this->subject->getRequest();
  }

  /**
   * {@inheritdoc}
   */
  public function getLangCode() {
    return $this->subject->getLangCode();
  }

  /**
   * {@inheritdoc}
   */
  public function setLangCode($langcode) {
    $this->subject->setLangCode($langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function getOptions() {
    return $this->subject->getOptions();
  }

  /**
   * {@inheritdoc}
   */
  public function addOptions(array $options) {
    $this->subject->addOptions($options);
  }

  /**
   * {@inheritdoc}
   */
  public function getContext($identifier) {
    return $this->subject->getContext($identifier);
  }

  /**
   * {@inheritdoc}
   */
  public function index() {
    return $this->subject->index();
  }

  /**
   * {@inheritdoc}
   */
  public function create($object) {
    return $this->subject->create($object);
  }

  /**
   * {@inheritdoc}
   */
  public function view($identifier) {
    $context = $this->getContext($identifier);
    $cached_data = $this->getRenderedCache($context);
    if (!empty($cached_data->data)) {
      return $cached_data->data;
    }
    $output = $this->subject->view($identifier);

    $this->setRenderedCache($output, $context);
    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function viewMultiple(array $identifiers) {
    $context = $this->getContext($identifiers);
    $cached_data = $this->getRenderedCache($context);
    if (!empty($cached_data->data)) {
      return $cached_data->data;
    }
    $output = $this->subject->viewMultiple($identifiers);

    $this->setRenderedCache($output, $context);
    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function update($identifier, $object, $replace = TRUE) {
    return $this->subject->update($identifier, $object, $replace);
  }

  /**
   * {@inheritdoc}
   */
  public function remove($identifier) {
    $this->subject->remove($identifier);
  }

  /**
   * Get an entry from the rendered cache.
   *
   * @param array $context
   *   An associative array with additional information to build the cache ID.
   *
   * @return object
   *   The cache with rendered entity as returned by DataProviderEntity::view().
   *
   * @see DataProviderEntity::view()
   */
  protected function getRenderedCache(array $context = array()) {
    // TODO: The render cache information will not be in the data provider
    // options annotation. That means that it will not make it through to this
    // class from the ResourceInterface object. We need to add
    // $dataProvider->addOptions(array(
    // 'renderCache' => Resource::getPluginDefinition()['renderCache']),
    // ).
    $options = $this->getOptions();
    $cache_info = $options['renderCache'];
    if (!$cache_info['render']) {
      return NULL;
    }

    $cid = $this->generateCacheId($context);
    return $this->cacheController->get($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function generateCacheId(array $context = array()) {
    // Get the cache ID from the selected params. We will use a complex cache
    // ID for smarter invalidation. The cache id will be like:
    // v<major version>.<minor version>::uu<user uid>::pa<params array>
    // The code before every bit is a 2 letter representation of the label.
    // For instance, the params array will be something like:
    // fi:id,title::re:admin
    // When the request has ?fields=id,title&restrict=admin
    $options = $this->getOptions();

    // TODO: Pass in the resource information needed here. I know, I know, …
    $version = $options['resource']['version'];
    $account = $this->getAccount();

    $cache_info = $options['renderCache'];
    if ($cache_info['granularity'] == DRUPAL_CACHE_PER_USER) {
      $account_cid = '::uu' . $account->uid;
    }
    elseif ($cache_info['granularity'] == DRUPAL_CACHE_PER_ROLE) {
      // Instead of encoding the user ID in the cache ID add the role ids.
      $account_cid = '::ur' . implode(',', array_keys($account->roles));
    }
    else {
      throw new NotImplementedException(sprintf('The selected cache granularity (%s) is not supported.', $cache_info['granularity']));
    }
    $base_cid = 'v' . $version['major'] . '.' . $version['minor'] . '::' . $options['resource']['name'] . $account_cid . '::pa';

    // Now add the context part to the cid.
    $cid_params = static::addCidParams($context);
    if (Request::isReadMethod($this->getRequest()->getMethod())) {
      // We don't want to split the cache with the body data on write requests.
      $this->getRequest()->clearApplicationData();
      $cid_params = array_merge($cid_params, static::addCidParams($this->getRequest()->getParsedInput()));
    }

    return $base_cid . implode('::', $cid_params);
  }

  /**
   * Get the cache id parameters based on the keys.
   *
   * @param array $keys
   *   Keys to turn into cache id parameters.
   *
   * @return array
   *   The cache id parameters.
   */
  protected static function addCidParams(array $keys) {
    $cid_params = array();
    foreach ($keys as $param => $value) {
      // Some request parameters don't affect how the resource is rendered, this
      // means that we should skip them for the cache ID generation.
      if (in_array($param, array(
        'filter',
        'loadByFieldName',
        'page',
        'q',
        'range',
        'sort',
      ))) {
        continue;
      }
      // Make sure that ?fields=title,id and ?fields=id,title hit the same cache
      // identifier.
      $values = explode(',', $value);
      sort($values);
      $value = implode(',', $values);

      $cid_params[] = substr($param, 0, 2) . ':' . $value;
    }
    return $cid_params;
  }

  /**
   * Store an entry in the rendered cache.
   *
   * @param mixed $data
   *   The data to be stored into the cache generated by
   *   \RestfulEntityInterface::viewEntity().
   * @param array $context
   *   An associative array with additional information to build the cache ID.
   *
   * @return array
   *   The rendered entity as returned by \RestfulEntityInterface::viewEntity().
   *
   * @see \RestfulEntityInterface::viewEntity().
   */
  protected function setRenderedCache($data, array $context = array()) {
    $options = $this->getOptions();
    $cache_info = $options['renderCache'];
    if (!$cache_info['render']) {
      return;
    }

    $cid = $this->generateCacheId($context);
    $this->cacheController->set($cid, $data, $cache_info['expire']);
  }

}
