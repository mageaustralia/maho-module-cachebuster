<?php

declare(strict_types=1);

use Maho\Config\Observer as MahoObserver;
use Maho\Event\Observer;

/**
 * Maho
 *
 * @package    MageAustralia_CacheBuster
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Hooks `controller_action_postdispatch` and post-processes the HTML
 * response body to version-stamp same-origin asset URLs. See
 * Helper/Data.php for the actual rewriting logic.
 *
 * Why `controller_action_postdispatch` and not `http_response_send_before`:
 *   - It fires immediately after the controller action populates the
 *     response body, but before the response is sent  -  and before any
 *     downstream cache (FPC, varnish-as-php, etc.) snapshots it.
 *   - It's a per-action attribute-registered event, which Maho 26+
 *     compiles into `vendor/composer/maho_attributes.php`. The legacy
 *     `<events>` XML wiring for response-cycle events is not always
 *     picked up reliably under the new compiled-attribute dispatcher.
 *
 * Run `composer dump-autoload` after installing this module  -  the
 * #[Observer] attribute is compiled at autoload time, not at runtime.
 */
class MageAustralia_CacheBuster_Model_Observer
{
    #[MahoObserver('controller_action_postdispatch', area: 'frontend', type: 'singleton')]
    #[MahoObserver('controller_action_postdispatch', area: 'adminhtml', type: 'singleton')]
    public function bustResponse(Observer $observer): void
    {
        try {
            $this->_doBustResponse($observer);
        } catch (Throwable $e) {
            // Never let a cache-bust failure take down the page. Log and
            // ship the original response untouched.
            Mage::logException($e);
        }
    }

    private function _doBustResponse(Observer $observer): void
    {
        /** @var MageAustralia_CacheBuster_Helper_Data $helper */
        $helper = Mage::helper('mageaustralia_cachebuster');

        $area = (string) Mage::getDesign()->getArea();
        if (!$helper->isEnabledForArea($area)) {
            return;
        }

        $controller = $observer->getEvent()->getControllerAction();
        if (!$controller instanceof Mage_Core_Controller_Varien_Action) {
            return;
        }

        $response = $controller->getResponse();
        if (!$response instanceof Mage_Core_Controller_Response_Http) {
            return;
        }

        // Only touch HTML responses. JSON/XML/binary/redirects pass through.
        if (!$this->_isHtmlResponse($response)) {
            return;
        }

        // Zend's getBody() is typed string|array|null in stubs (the array
        // branch is only reached with a truthy first argument, which we
        // don't pass). Narrow to string so the cast below is type-safe.
        $body = $response->getBody();
        if (!is_string($body) || $body === '') {
            return;
        }

        $busted = $helper->bustHtml($body);
        if ($busted === $body) {
            return;
        }

        // The Zend response object stores the body in named segments;
        // passing null as the name replaces the entire body with a single
        // segment (matches the layout-render default).
        $response->setBody($busted, null);
    }

    /**
     * Inspect response headers + status to decide whether the body is
     * HTML. Conservative: anything not unambiguously `text/html` is left
     * alone. Defaults to true when Content-Type isn't yet set  -  Maho's
     * normal page render path leaves Content-Type unset until send time,
     * and the rendered body is HTML by default.
     */
    private function _isHtmlResponse(Mage_Core_Controller_Response_Http $response): bool
    {
        $status = (int) $response->getHttpResponseCode();
        if ($status >= 300 && $status < 400) {
            return false;
        }

        foreach ($response->getHeaders() as $header) {
            if (!isset($header['name'], $header['value'])) {
                continue;
            }
            if (strcasecmp((string) $header['name'], 'Content-Type') === 0) {
                $value = strtolower((string) $header['value']);
                return str_starts_with($value, 'text/html');
            }
        }

        return true;
    }
}
