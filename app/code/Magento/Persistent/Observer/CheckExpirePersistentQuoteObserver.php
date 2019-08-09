<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Persistent\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;

/**
 * Observer of expired session
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class CheckExpirePersistentQuoteObserver implements ObserverInterface
{
    /**
     * Customer session
     *
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;

    /**
     * Checkout session
     *
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * Core event manager proxy
     *
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $_eventManager = null;

    /**
     * Persistent session
     *
     * @var \Magento\Persistent\Helper\Session
     */
    protected $_persistentSession = null;

    /**
     * @var \Magento\Persistent\Model\QuoteManager
     */
    protected $quoteManager;

    /**
     * Persistent data
     *
     * @var \Magento\Persistent\Helper\Data
     */
    protected $_persistentData = null;

    /**
     * Request
     *
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;

    /**
     * Checkout Page path
     *
     * @var string
     */
    private $checkoutPagePath = 'checkout';

    /**
     * @var Quote
     */
    private $quote;

    /**
     * @param \Magento\Persistent\Helper\Session $persistentSession
     * @param \Magento\Persistent\Helper\Data $persistentData
     * @param \Magento\Persistent\Model\QuoteManager $quoteManager
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Framework\App\RequestInterface $request
     */
    public function __construct(
        \Magento\Persistent\Helper\Session $persistentSession,
        \Magento\Persistent\Helper\Data $persistentData,
        \Magento\Persistent\Model\QuoteManager $quoteManager,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\App\RequestInterface $request
    ) {
        $this->_persistentSession = $persistentSession;
        $this->quoteManager = $quoteManager;
        $this->_customerSession = $customerSession;
        $this->_checkoutSession = $checkoutSession;
        $this->_eventManager = $eventManager;
        $this->_persistentData = $persistentData;
        $this->request = $request;
    }

    /**
     * Check and clear session data if persistent session expired
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->_persistentData->canProcess($observer)) {
            return;
        }

        //clear persistent when persistent data is disabled
        if ($this->isPersistentQuoteOutdated()) {
            $this->_eventManager->dispatch('persistent_session_expired');
            $this->quoteManager->expire();
            $this->_checkoutSession->clearQuote();
            return;
        }

        if ($this->_persistentData->isEnabled() &&
            !$this->_persistentSession->isPersistent() &&
            !$this->_customerSession->isLoggedIn() &&
            $this->_checkoutSession->getQuoteId() &&
            !$this->isRequestFromCheckoutPage($this->request) &&
            // persistent session does not expire on onepage checkout page
            $this->isNeedToExpireSession()
        ) {
            $this->_eventManager->dispatch('persistent_session_expired');
            $this->quoteManager->expire();
            $this->_customerSession->setCustomerId(null)->setCustomerGroupId(null);
        }
    }

    /**
     * Checks if current quote marked as persistent and Persistence Functionality is disabled.
     *
     * @return bool
     */
    private function isPersistentQuoteOutdated(): bool
    {
        if ((!$this->_persistentData->isEnabled() || !$this->_persistentData->isShoppingCartPersist())
            && !$this->_customerSession->isLoggedIn()
            && $this->_checkoutSession->getQuoteId()) {
            return (bool)$this->getQuote()->getIsPersistent();
        }
        return false;
    }

    /**
     * Condition checker
     *
     * @return bool
     */
    private function isNeedToExpireSession(): bool
    {
        return $this->getQuote()->getIsPersistent() || $this->getQuote()->getCustomerIsGuest();
    }

    /**
     * Getter for Quote with micro optimization
     *
     * @return Quote
     */
    private function getQuote(): Quote
    {
        if ($this->quote === null) {
            $this->quote = $this->_checkoutSession->getQuote();
        }
        return $this->quote;
    }

    /**
     * Check current request is coming from onepage checkout page.
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return bool
     */
    private function isRequestFromCheckoutPage(\Magento\Framework\App\RequestInterface $request): bool
    {
        $requestUri = (string)$request->getRequestUri();
        $refererUri = (string)$request->getServer('HTTP_REFERER');

        /** @var bool $isCheckoutPage */
        $isCheckoutPage = (
            false !== strpos($requestUri, $this->checkoutPagePath) ||
            false !== strpos($refererUri, $this->checkoutPagePath)
        );

        return $isCheckoutPage;
    }
}
