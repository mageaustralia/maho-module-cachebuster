<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @package    MageAustralia_CacheBuster
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Hooks `http_response_send_before` and post-processes the HTML response
 * body to version-stamp same-origin asset URLs. See Helper/Data.php for
 * the actual rewriting logic.
 */
class MageAustralia_CacheBuster_Model_Observer
{
    public function bustResponse(Varien_Event_Observer $observer): void
    {
        try {
            $this->_doBustResponse($observer);
        } catch (\Throwable $e) {
            // Never let a cache-bust failure take down the page. Log and
            // ship the original response untouched.
            Mage::logException($e);
        }
    }

    private function _doBustResponse(Varien_Event_Observer $observer): void
    {
        /** @var MageAustralia_CacheBuster_Helper_Data $helper */
        $helper = Mage::helper('mageaustralia_cachebuster');

        $area = (string) Mage::getDesign()->getArea();
        if (!$helper->isEnabledForArea($area)) {
            return;
        }

        /** @var Mage_Core_Controller_Response_Http $response */
        $response = $observer->getEvent()->getResponse();
        if (!$response instanceof Mage_Core_Controller_Response_Http) {
            return;
        }

        // Only touch HTML responses. JSON/XML/binary/redirects pass through.
        if (!$this->_isHtmlResponse($response)) {
            return;
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return;
        }

        $busted = $helper->bustHtml($body);
        if ($busted === $body) {
            return;
        }

        // The Zend response object stores the body in named segments; passing
        // null as the name replaces the entire body with a single segment.
        $response->setBody($busted, null);
    }

    /**
     * Inspect the response headers (and the response code) to decide whether
     * the body should be treated as HTML. Conservative: anything that's not
     * unambiguously `text/html` is left alone.
     */
    private function _isHtmlResponse(Mage_Core_Controller_Response_Http $response): bool
    {
        // 3xx redirects have no useful body.
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

        // No Content-Type header set yet  -  Maho's default for rendered pages
        // is HTML, so opt in. If a downstream sender overrides Content-Type
        // after this event, the worst case is a few extra `?v=` query
        // strings on what turns out to be a non-HTML body  -  still parseable
        // by browsers, just unnecessary.
        return true;
    }
}
