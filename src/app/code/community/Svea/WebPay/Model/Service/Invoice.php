<?php

require_once Mage::getRoot() . '/code/community/Svea/WebPay/integrationLib/Includes.php';

/**
 * Class for Invoice payments specifics
 *
 * @category Payment
 * @package Svea_WebPay_Module_Magento
 * @author SveaWebPay <https://github.com/sveawebpay/magento-module>
 * @license https://github.com/sveawebpay/magento-module/blob/master/LICENSE.txt Apache License
 * @copyright (c) 2013, SveaWebPay (Svea Ekonomi AB)
 */
class Svea_WebPay_Model_Service_Invoice extends Svea_WebPay_Model_Service_Abstract
{
    protected $_code = 'svea_invoice';
    protected $_formBlockType = 'svea_webpay/payment_service_invoice';
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;

    /**
     * Take the Invoice path in Create Order object
     *
     * @param type $sveaObject
     * @return InvoicePayment
     */
    protected function _choosePayment($sveaObject)
    {
        return $sveaObject->useInvoicePayment();
    }

    /**
     * For Svea, Deliver order
     *
     * @param Varien_Object $payment
     * @param type $amount
     * @return type
     */
    public function capture(Varien_Object $payment, $amount)
    {
        // Check if we are trying to deliver an existing order, or this is an autodeliver
        // If no flag e.g. -capture, or if no transactionid, assume this is not sent from admin
        //TODO: Make sure compatible with onestep checkout
        $sveaOrderId = $payment->getTransactionId();
        if (empty($sveaOrderId) || preg_match('/[A-Za-z]/', $sveaOrderId) == FALSE) {
            if (!$this->getConfigData('autodeliver')) {
                $errorTranslated = Mage::helper('svea_webpay')->responseCodes("", 'no_orderid');
                Mage::throwException($errorTranslated);
            }
            $sveaOrderId = $this->getInfoInstance()
                    ->getAdditionalInformation('svea_order_id');
        }
        $order = $payment->getOrder();
        $paymentMethodConfig = $this->getSveaStoreConfClass($order->getStoreId());

        $invoice = $this->getCurrentInvoice();
        Mage::helper('svea_webpay')->getDeliverInvoiceRequest($invoice, $paymentMethodConfig, $sveaOrderId);
        $sveaObject = $invoice->getData('svea_deliver_request');
        $response = $sveaObject
                ->deliverInvoiceOrder()
                ->doRequest();
        
        if ($response->accepted == 1) {
            $successMessage = Mage::helper('svea_webpay')->__('delivered');
            $order->addStatusToHistory($this->getConfigData('paid_order_status'), $successMessage, false);
            $payment->setIsTransactionClosed(false);
            $paymentInfo = $this->getInfoInstance();
            $paymentInfo->setAdditionalInformation('svea_invoice_id', $response->invoiceId);
            $order->save();
        } else {
            $errorMessage = $response->errormessage;
            $statusCode = $response->resultcode;
            $errorTranslated = Mage::helper('svea_webpay')->responseCodes($statusCode, $errorMessage);
            if ($order->canCancel()) {
                $order->addStatusToHistory($order->getStatus(), $errorTranslated, false);
                $order->cancel();
                $order->save();
            }

            return Mage::throwException($errorTranslated);
        }
    }

    /**
     * For Svea, Deliver order as Credit Invoice
     *
     * @param Varien_Object $payment
     * @param type $amount
     * @return type
     */
    public function refund(Varien_Object $payment, $amount)
    {
        // Alternative: $sveaOrderId = $payment->getTransactionId(), comes
        // with -refund
        $sveaOrderId = $this->getInfoInstance()
                ->getAdditionalInformation('svea_order_id');

        // Check if we are trying to deliver an existing order, or this is
        // an autodeliver
        if (empty($sveaOrderId)) {
            if (!$this->getConfigData('autodeliver')) {
                $errorTranslated = Mage::helper('svea_webpay')->responseCodes("", 'no_orderid');
                Mage::throwException($errorTranslated);
            }
        }

        $order = $payment->getOrder();

        $paymentMethodConfig = $this->getSveaStoreConfClass($order->getStoreId());
        Mage::helper('svea_webpay')->getRefundRequest($payment, $paymentMethodConfig, $sveaOrderId);
        $sveaObject = $order->getData('svea_refund_request');
        $response = $sveaObject->setCreditInvoice($this->getInfoInstance()
                ->getAdditionalInformation('svea_invoice_id'))
                ->deliverInvoiceOrder()->doRequest();

        if ($response->accepted == 1) {
            return parent::refund($payment, $amount);
        } else {
            $errorMessage = $response->errormessage;
            $statusCode = $response->resultcode;
            $errorTranslated = Mage::helper('svea_webpay')
                    ->responseCodes($statusCode, $errorMessage);

            return Mage::throwException($errorTranslated);
        }
    }

    /**
     * End close order request
     *
     * @param type $sveaObject
     * @return type Svea Create order response
     */
    protected function _closeOrder($sveaObject)
    {
        return $sveaObject->closeInvoiceOrder()->doRequest();
    }
}