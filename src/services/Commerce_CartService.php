<?php
namespace Craft;

use Commerce\Helpers\CommerceDbHelper;

/**
 * Cart service.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   http://craftcommerce.com/license Craft Commerce License Agreement
 * @see       http://craftcommerce.com
 * @package   craft.plugins.commerce.services
 * @since     1.0
 */
class Commerce_CartService extends BaseApplicationComponent
{
    /** @var string Session key for storing the cart number */
    protected $cookieCartId = 'commerce_cookie';
    /** @var Commerce_OrderModel */
    private $cart;

    /**
     * @param Commerce_OrderModel $order
     * @param int $purchasableId
     * @param int $qty
     * @param string $note
     * @param string $error
     *
     * @return bool
     * @throws \Exception
     */
    public function addToCart($order, $purchasableId, $qty = 1, $note = '', &$error = '')
    {
        CommerceDbHelper::beginStackedTransaction();

        //saving current cart if it's new and empty
        if (!$order->id) {
            if (!craft()->commerce_orders->save($order)) {
                throw new Exception(Craft::t('Error on creating empty cart'));
            }
        }

        //filling item model
        $lineItem = craft()->commerce_lineItems->getByOrderPurchasable($order->id, $purchasableId);

        if ($lineItem->id) {
            $lineItem->qty += $qty;
        } else {
            $lineItem = craft()->commerce_lineItems->create($purchasableId, $order->id, $qty);
        }

        if ($note) {
            $lineItem->note = $note;
        }

        try {
            if (craft()->commerce_lineItems->save($lineItem)) {
                craft()->commerce_orders->save($order);
                CommerceDbHelper::commitStackedTransaction();

                //raising event
                $event = new Event($this, [
                    'lineItem' => $lineItem,
                    'order' => $order,
                ]);
                $this->onAddToCart($event);

                return true;
            }
        } catch (\Exception $e) {
            CommerceDbHelper::rollbackStackedTransaction();
            throw $e;
        }

        CommerceDbHelper::rollbackStackedTransaction();

        $errors = $lineItem->getAllErrors();
        $error = array_pop($errors);

        return false;
    }

    /**
     * Event method.
     * Event params: order(Commerce_OrderModel), lineItem (Commerce_LineItemModel)
     *
     * @param \CEvent $event
     *
     * @throws \CException
     */
    public function onAddToCart(\CEvent $event)
    {
        $params = $event->params;
        if (empty($params['order']) || !($params['order'] instanceof Commerce_OrderModel)) {
            throw new Exception('onAddToCart event requires "order" param with OrderModel instance');
        }

        if (empty($params['lineItem']) || !($params['lineItem'] instanceof Commerce_LineItemModel)) {
            throw new Exception('onAddToCart event requires "lineItem" param with LineItemModel instance');
        }
        $this->raiseEvent('onAddToCart', $event);
    }

    /**
     * Forgets a Cart by deleting its cookie.
     *
     * @param Commerce_OrderModel $cart
     */
    public function forgetCart(Commerce_OrderModel $cart)
    {
        $cookieId = $this->cookieCartId;
        craft()->userSession->deleteStateCookie($cookieId);
    }

    /**
     * @param Commerce_OrderModel $cart
     * @param string $code
     * @param string $error
     *
     * @return bool
     * @throws Exception
     * @throws \Exception
     */
    public function applyCoupon(Commerce_OrderModel $cart, $code, &$error = '')
    {
        if (empty($code) || craft()->commerce_discounts->checkCode($code,
                $cart->customerId, $error)
        ) {
            $cart->couponCode = $code ?: null;
            craft()->commerce_orders->save($cart);

            return true;
        } else {
            return false;
        }
    }

    /**
     * Set shipping method to the current order
     *
     * @param Commerce_OrderModel $cart
     * @param int $shippingMethodId
     * @param string $error ;
     *
     * @return bool
     * @throws Exception
     * @throws \Exception
     */
    public function setShippingMethod(
        Commerce_OrderModel $cart,
        $shippingMethodId,
        &$error = ""
    )
    {
        $method = craft()->commerce_shippingMethods->getById($shippingMethodId);
        if (!$method->id) {
            $error = Craft::t('Bad shipping method');
            return false;
        }

        if (!craft()->commerce_shippingMethods->getMatchingRule($cart, $method)) {
            $error = Craft::t('Shipping method not available');
            return false;
        }

        $cart->shippingMethodId = $shippingMethodId;
        craft()->commerce_orders->save($cart);

        return true;
    }

    /**
     * Set shipping method to the current order
     *
     * @param Commerce_OrderModel $cart
     * @param int $paymentMethodId
     * @param string $error
     *
     * @return bool
     * @throws \Exception
     */
    public function setPaymentMethod(Commerce_OrderModel $cart, $paymentMethodId, &$error = "")
    {
        $method = craft()->commerce_paymentMethods->getById($paymentMethodId);
        if (!$method->id || !$method->frontendEnabled) {
            $error = Craft::t('Payment method does not exist or is not allowed.');
            return false;
        }

        $cart->paymentMethodId = $paymentMethodId;
        craft()->commerce_orders->save($cart);

        return true;
    }

    /**
     * @param Commerce_OrderModel $cart
     * @param $email
     * @param string $error
     *
     * @return bool
     */
    public function setEmail(Commerce_OrderModel $cart, $email, &$error = "")
    {

        $validator = new \CEmailValidator;
        $validator->allowEmpty = false;

        if (!$validator->validateValue($email)) {
            $error = Craft::t('Not a valid email address');
            return false;
        }

        try {
            // we need to force a persisted customer so get a customer id
            $this->getCart()->customerId = craft()->commerce_customers->getCustomerId();
            $customer = craft()->commerce_customers->getCustomer();
            if (!$customer->userId) {
                $customer->email = $email;
                craft()->commerce_customers->save($customer);
                $cart->email = $customer->email;
                craft()->commerce_orders->save($cart);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            return false;
        }
        return true;
    }

    /**
     * @return mixed
     * @throws Exception
     * @throws \Exception
     */
    public function getCart()
    {

        if (!isset($this->cart)) {
            $number = $this->_getSessionCartNumber();

            if ($cart = $this->_getCartRecordByNumber($number)) {
                $this->cart = Commerce_OrderModel::populateModel($cart);
            } else {
                $this->cart = new Commerce_OrderModel;
                $this->cart->number = $number;
            }

            $this->cart->lastIp = craft()->request->getIpAddress();

            // Update the cart if the customer has changed and recalculate the cart.
            $customer = craft()->commerce_customers->getCustomer();
            if ($customer->id) {
                if (!$this->cart->isEmpty() && $this->cart->customerId != $customer->id) {
                    $this->cart->customerId = $customer->id;
                    $this->cart->email = $customer->email;
                    $this->cart->billingAddressId = null;
                    $this->cart->shippingAddressId = null;
                    $this->cart->billingAddressData = null;
                    $this->cart->shippingAddressData = null;
                    craft()->commerce_orders->save($this->cart);
                }
            }
        }

        return $this->cart;
    }

    /**
     * @return mixed|string
     */
    private function _getSessionCartNumber()
    {
        $cookieId = $this->cookieCartId;
        $cartNumber = craft()->userSession->getStateCookieValue($cookieId);

        if (!$cartNumber) {
            $cartNumber = md5(uniqid(mt_rand(), true));
            $configInterval = craft()->config->get('cartCookieDuration', 'commerce');
            $interval = new DateInterval($configInterval);
            $cartExpiry = date_create('@0')->add($interval)->getTimestamp();
            craft()->userSession->saveCookie($cookieId, $cartNumber, $cartExpiry);
        }

        return $cartNumber;
    }

    /**
     * @param string $number
     *
     * @return Commerce_OrderRecord
     */
    private function _getCartRecordByNumber($number)
    {
        $cart = Commerce_OrderRecord::model()->findByAttributes([
            'number' => $number,
            'dateOrdered' => null,
        ]);

        return $cart;
    }

    /**
     * @TODO check that line item belongs to the current user
     *
     * @param Commerce_OrderModel $cart
     * @param int $lineItemId
     *
     * @throws Exception
     * @throws \Exception
     */
    public function removeFromCart(Commerce_OrderModel $cart, $lineItemId)
    {
        $lineItem = craft()->commerce_lineItems->getById($lineItemId);

        if (!$lineItem->id) {
            throw new Exception('Line item not found');
        }

        CommerceDbHelper::beginStackedTransaction();
        try {
            craft()->commerce_lineItems->delete($lineItem);

            craft()->commerce_orders->save($cart);

            //raising event
            $event = new Event($this, [
                'lineItemId' => $lineItemId,
                'order' => $cart
            ]);
            $this->onRemoveFromCart($event);
        } catch (\Exception $e) {
            CommerceDbHelper::rollbackStackedTransaction();
            throw $e;
        }

        CommerceDbHelper::commitStackedTransaction();
    }

    /**
     * Event method.
     * Event params: order(Commerce_OrderModel), lineItemId (int)
     *
     * @param \CEvent $event
     *
     * @throws \CException
     */
    public function onRemoveFromCart(\CEvent $event)
    {
        $params = $event->params;
        if (empty($params['order']) || !($params['order'] instanceof Commerce_OrderModel)) {
            throw new Exception('onRemoveFromCart event requires "order" param with OrderModel instance');
        }

        if (empty($params['lineItemId']) || !is_numeric($params['lineItemId'])) {
            throw new Exception('onRemoveFromCart event requires "lineItemId" param');
        }
        $this->raiseEvent('onRemoveFromCart', $event);
    }

    /**
     * Remove all items from a cart
     *
     * @param Commerce_OrderModel $cart
     *
     * @throws \Exception
     */
    public function clearCart(Commerce_OrderModel $cart)
    {
        CommerceDbHelper::beginStackedTransaction();
        try {
            craft()->commerce_lineItems->deleteAllByOrderId($cart->id);
            craft()->commerce_orders->save($cart);
        } catch (\Exception $e) {
            CommerceDbHelper::rollbackStackedTransaction();
            throw $e;
        }

        CommerceDbHelper::commitStackedTransaction();
    }

    /**
     * Removes all carts that are incomplete and older than the config setting.
     *
     * @return int
     * @throws \Exception
     */
    public function purgeIncompleteCarts()
    {
        $carts = $this->getCartsToPurge();
        if ($carts) {
            $ids = array_map(function (Commerce_OrderModel $cart) {
                return $cart->id;
            }, $carts);
            craft()->elements->deleteElementById($ids);

            return count($ids);
        }

        return 0;
    }

    /**
     * Which Carts need to be deleted
     *
     * @return Commerce_OrderModel[]
     */
    public function getCartsToPurge()
    {

        $configInterval = craft()->config->get('purgeInactiveCartsDuration', 'commerce');
        $edge = new DateTime();
        $interval = new DateInterval($configInterval);
        $interval->invert = 1;
        $edge->add($interval);

        $records = Commerce_OrderRecord::model()->findAllByAttributes(
            [
                'dateOrdered' => null,
            ],
            'dateUpdated <= :edge',
            ['edge' => $edge->format('Y-m-d H:i:s')]
        );

        return Commerce_OrderModel::populateModels($records);
    }
}
