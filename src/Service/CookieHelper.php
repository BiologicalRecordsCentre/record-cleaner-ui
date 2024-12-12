<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Service\ApiHelper.
 *
 * With thanks to
 * https://www.daggerhartlab.com/cookie-services-how-to-handle-cookies-in-drupal-symfony/
 */

namespace Drupal\record_cleaner\Service;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ResponseEvent;


class CookieHelper implements EventSubscriberInterface {

  protected $request;

  protected $value;

  protected $delete;

  // Pantheon hosting requires a session-style cookie to break through cache.
  // See https://docs.pantheon.io/caching-advanced-topics#using-your-own-session-style-cookies
  protected $name = 'SESSrecordcleanerui';

  // Path for which the cookie is valid.
  protected $path = '/record_cleaner/ui';

  /**
   * Constructor.
   *
   * @param RequestStack $request_stack
   */
  public function __construct(RequestStack $request_stack) {
    $this->request = $request_stack->getCurrentRequest();
  }

  /**
   * Get an array of event names to listen for.
   *
   * @return array
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::RESPONSE => 'onResponse',
    ];
  }

  /**
   * Set the cookie value.
   *
   * @param array $value
   */
  public function setCookie($value) {

    // Function to remove null values from array.
    $callback = function($array) {
      $result = array_filter($array, function($v) {
        return isset($v);
      });
      return $result;
    };

    $this->value = array_map($callback, $value);
  }

  /**
   * Get the cookie value.
   *
   * If mid-request and a new value has been set, return that. Otherwise return
   * the value sent in the request.
   *
   * @return array
   */
  public function getCookie() {
    if (isset($this->value)) {
      return $this->value;
    }
    else {
      $value = $this->request->cookies->get($this->name);
      if (isset($value)) {
        return json_decode($value, TRUE);
      }
    }
  }

  /**
   * Flag cookie for deletion when response is sent.
   */
  public function deleteCookie() {
    $this->delete = TRUE;
  }

  /**
   * Check if cookie exists.
   *
   * @return bool
   */
  public function hasCookie() {
    return $this->request->cookies->has($this->name);
  }

  /**
   * Event handler to add cookie to response.
   *
   * @param ResponseEvent $event
   */
  public function onResponse(ResponseEvent $event) {
    $response = $event->getResponse();

    // Update cookie in preference to deleting it.
    if (isset($this->value)) {
      $expire = time() + 60 * 60 * 24 * 365;
      $value = json_encode($this->value);
      $cookie = new Cookie($this->name, $value, $expire, $this->path);
      $response->headers->setCookie($cookie);
    }
    elseif (isset($this->delete)) {
      $response->headers->clearCookie($this->name, $this->path);
    }
  }
}
