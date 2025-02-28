<?php

namespace Nikolag\Square;

use Nikolag\Core\Abstracts\CorePaymentService;
use Nikolag\Square\Builders\CustomerBuilder;
use Nikolag\Square\Builders\OrderBuilder;
use Nikolag\Square\Builders\ProductBuilder;
use Nikolag\Square\Builders\SquareRequestBuilder;
use Nikolag\Square\Contracts\SquareServiceContract;
use Nikolag\Square\Exceptions\AlreadyUsedSquareProductException;
use Nikolag\Square\Exceptions\InvalidSquareAmountException;
use Nikolag\Square\Exceptions\InvalidSquareCurrencyException;
use Nikolag\Square\Exceptions\InvalidSquareCvvException;
use Nikolag\Square\Exceptions\InvalidSquareExpirationDateException;
use Nikolag\Square\Exceptions\InvalidSquareNonceException;
use Nikolag\Square\Exceptions\InvalidSquareOrderException;
use Nikolag\Square\Exceptions\InvalidSquareZipcodeException;
use Nikolag\Square\Exceptions\MissingPropertyException;
use Nikolag\Square\Models\Transaction;
use Nikolag\Square\Utils\Constants;
use Nikolag\Square\Utils\Util;
use Square\Exceptions\ApiException;
use Square\Models\CreateCustomerRequest;
use Square\Models\CreateOrderRequest;
use Square\Models\Error;
use Square\Models\ListLocationsResponse;
use Square\Models\ListPaymentsResponse;
use Square\Models\UpdateCustomerRequest;
use stdClass;

class SquareService extends CorePaymentService implements SquareServiceContract
{
    /**
     * @var stdClass
     */
    private $orderCopy;
    /**
     * @var OrderBuilder
     */
    private $orderBuilder;
    /**
     * @var SquareRequestBuilder
     */
    private $squareBuilder;
    /**
     * @var ProductBuilder
     */
    private $productBuilder;
    /**
     * @var CustomerBuilder
     */
    protected $customerBuilder;
    /**
     * @var string
     */
    private $locationId;
    /**
     * @var string
     */
    private $currency;
    /**
     * @var CreateOrderRequest
     */
    private $createOrderRequest;
    /**
     * @var CreateCustomerRequest
     */
    private $createCustomerRequest;

    public function __construct(SquareConfig $squareConfig)
    {
        $this->config = $squareConfig;
        $this->orderCopy = new stdClass();
        $this->orderBuilder = new OrderBuilder();
        $this->squareBuilder = new SquareRequestBuilder();
        $this->productBuilder = new ProductBuilder();
        $this->customerBuilder = new CustomerBuilder();
    }

    /**
     * List locations.
     *
     * @return ListLocationsResponse
     *
     * @throws ApiException
     */
    public function locations()
    {
        return $this->config->locationsAPI()->listLocations()->getResult();
    }

    /**
     * Save a customer.
     *
     * @return void
     *
     * @throws ApiException
     */
    private function _saveCustomer()
    {
        if (! $this->getCustomer()->payment_service_id) {
            $response = $this->config->customersAPI()->createCustomer($this->getCreateCustomerRequest())->getResult();
            $this->getCustomer()->payment_service_id = $response->getCustomer()->getId();
        } else {
            $this->config->customersAPI()->updateCustomer($this->getCustomer()->payment_service_id, $this->getCreateCustomerRequest());
        }

        $this->getCustomer()->save();
        // If merchant exists and if merchant doesn't have customer
        if ($this->getMerchant() && ! $this->getMerchant()->hasCustomer($this->getCustomer()->email)) {
            // Attach seller to the buyer
            $this->getCustomer()->merchants()->attach($this->getMerchant()->id);
        }
    }

    /**
     * Save order to database and if required
     * also save to square vault.
     *
     * @param  bool  $saveToSquare
     * @return void
     *
     * @throws InvalidSquareOrderException
     * @throws MissingPropertyException
     * @throws Exception
     * @throws ApiException
     */
    private function _saveOrder(bool $saveToSquare = false)
    {
        $this->order = $this->orderBuilder->buildOrderFromOrderCopy($this->getOrder(), $this->orderCopy);
        //If property locationId doesn't exist throw error
        if (! $this->locationId) {
            throw new MissingPropertyException('$locationId property is missing', 500);
        }
        //If order doesn't have any products throw error
        if ($this->getOrder()->products()->count() == 0) {
            throw new InvalidSquareOrderException('Object Order must have at least 1 Product', 500);
        }
        //If local order doesn't have square order identifier to which to relate
        //local order
        $property = config('nikolag.connections.square.order.service_identifier');
        if (! $this->getOrder()->hasAttribute($property)) {
            throw new InvalidSquareOrderException('Table orders is missing a required column: '.$property, 500);
        }
        $orderRequest = $this->squareBuilder->buildOrderRequest($this->getOrder(), $this->locationId, $this->currency);
        $this->setCreateOrderRequest($orderRequest);
        // If want to save to square, make a request
        if ($saveToSquare) {
            $response = $this->config->ordersAPI()->createOrder($this->getCreateOrderRequest());
            if ($response->isError()) {
                throw $this->_handleChargeOrSaveException($response->getErrors());
            }
            $response = $response->getResult();
            //Save id of a real order inside of Square to our local model for future use
            $this->getOrder()->{$property} = $response->getOrder()->getId();
        }
        $this->getOrder()->save();
    }

    /**
     * @param  Error[]  $errors
     * @return Exception
     */
    private function _handleChargeOrSaveException(array $errors)
    {
        //Set exception to be first in array of errors
        $exceptionJSON = $errors[0];
        $exception = new Exception($exceptionJSON->getDetail());

        if ($exceptionJSON->getCategory() === Constants::INVALID_REQUEST_ERROR) {
            if ($exceptionJSON->getCode() === Constants::BAD_REQUEST) {
                $exception = new InvalidSquareNonceException($exceptionJSON->getDetail(), 404);
            } elseif ($exceptionJSON->getCode() === Constants::INVALID_VALUE) {
                $exception = new InvalidSquareCurrencyException($exceptionJSON->getDetail(), 400);
            }
        } elseif ($exceptionJSON->getCategory() === Constants::PAYMENT_METHOD_ERROR) {
            if ($exceptionJSON->getCode() === Constants::INVALID_EXPIRATION) {
                $exception = new InvalidSquareExpirationDateException($exceptionJSON->getDetail(), 400);
            } elseif ($exceptionJSON->getCode() === Constants::VERIFY_POSTAL_CODE) {
                $exception = new InvalidSquareZipcodeException($exceptionJSON->getDetail(), 402);
            } elseif ($exceptionJSON->getCode() === Constants::VERIFY_CVV) {
                $exception = new InvalidSquareCvvException($exceptionJSON->getDetail(), 402);
            }
        }

        throw $exception;
    }

    /**
     * Save collected data.
     *
     * @return self
     *
     * @throws Exception on non-2xx response
     * @throws ApiException
     */
    public function save()
    {
        try {
            if ($this->getCustomer()) {
                $this->_saveCustomer();
            }
            if ($this->getOrder()) {
                $this->_saveOrder();
            }
        } catch (ApiException $exception) {
            throw $exception;
        } catch (MissingPropertyException $e) {
            throw new MissingPropertyException('Required fields are missing', 500, $e);
        } catch (InvalidSquareOrderException $e) {
            throw new MissingPropertyException('Required column is missing from the table', 500, $e);
        }

        return $this;
    }

    /**
     * Charge a customer.
     *
     * @param  array  $data
     * @return Transaction
     *
     * @throws ApiException
     * @throws Exception on non-2xx response
     * @throws InvalidSquareAmountException
     * @throws MissingPropertyException
     */
    public function charge(array $data)
    {
        $location_id = array_key_exists('location_id', $data) ? $data['location_id'] : null;
        $currency = array_key_exists('currency', $data) ? $data['currency'] : 'USD';
        $prepData = [
            'idempotency_key' => uniqid(),
            'amount_money'    => [
                'amount'   => $data['amount'],
                'currency' => $currency,
            ],
            'autocomplete' => true,
            'source_id' => $data['source_id'],
            'location_id' => $location_id,
            'note' => array_key_exists('note', $data) ? $data['note'] : null,
            'reference_id' => array_key_exists('reference_id', $data) ? (string) $data['reference_id'] : null,
        ];

        // Location id is now mandatory to know under which Location we are doing a charge on
        if (! $prepData['location_id']) {
            throw new MissingPropertyException('Required field \'location_id\' is missing', 500);
        }

        $transaction = new Transaction(['status' => Constants::TRANSACTION_STATUS_OPENED, 'amount' => $data['amount'], 'currency' => $currency]);
        // Save and attach merchant
        if ($this->getMerchant()) {
            $transaction->merchant()->associate($this->getMerchant());
        }
        // Save and attach customer
        if ($this->getCustomer()) {
            $this->_saveCustomer();
            // Save customer into the table for further use
            $transaction->customer()->associate($this->getCustomer());
            // Set customer id for square from model
            $prepData['customer_id'] = $this->getCustomer()->payment_service_id;
        }
        // Save and attach order
        if ($this->getOrder()) {
            try {
                // Calculate the total order amount
                $calculatedCost = Util::calculateTotalOrderCost($this->orderCopy);
                // If order total does not match charge amount, throw error
                if ($calculatedCost != $data['amount']) {
                    throw new InvalidSquareAmountException('The charge amount does not match the order total.', 500);
                }
                // Save order to both database and square
                $this->_saveOrder(true);
                // Connect order with transaction
                $transaction->order()->associate($this->getOrder());
                // Get table column name for square id property
                $property = config('nikolag.connections.square.order.service_identifier');
                // Set order id for square from order model property for square identifier
                $prepData['order_id'] = $this->getOrder()->{$property};
            } catch (MissingPropertyException $e) {
                throw new MissingPropertyException('Required field is missing', 500, $e);
            } catch (InvalidSquareOrderException $e) {
                throw new MissingPropertyException('Required column is missing from the table', 500, $e);
            }
        }
        $transaction->save();

        $chargeRequest = $this->squareBuilder->buildChargeRequest($prepData);
        $response = $this->config->paymentsAPI()->createPayment($chargeRequest);

        if ($response->isSuccess()) {
            $response = $response->getResult()->getPayment();

            $transaction->payment_service_id = $response->getId();
            $transaction->status = Constants::TRANSACTION_STATUS_PASSED;
            $transaction->save();
        } elseif ($response->isError()) {
            $transaction->payment_service_id = null;
            $transaction->status = Constants::TRANSACTION_STATUS_FAILED;
            $transaction->save();

            throw $this->_handleChargeOrSaveException($response->getErrors());
        }

        return $transaction;
    }

    /**
     * Payments directly from Square API.
     * Please check: https://developer.squareup.com/reference/square/payments-api/list-payments#query-parameters
     * for options that you can pass to this function.
     *
     * @param  array  $options
     * @return ListPaymentsResponse
     *
     * @throws ApiException
     */
    public function payments(array $options)
    {
        $options = [
            'location_id' => array_key_exists('location_id', $options) ? $options['location_id'] : null,
            'begin_time' => array_key_exists('begin_time', $options) ? $options['begin_time'] : null,
            'end_time' => array_key_exists('end_time', $options) ? $options['end_time'] : null,
            'sort_order' => array_key_exists('sort_order', $options) ? $options['sort_order'] : null,
            'cursor' => array_key_exists('cursor', $options) ? $options['cursor'] : null,
            'total' => array_key_exists('total', $options) ? $options['total'] : null,
            'last_4' => array_key_exists('last_4', $options) ? $options['last_4'] : null,
            'card_brand' => array_key_exists('card_brand', $options) ? $options['card_brand'] : null,
        ];

        return $this->config->paymentsAPI()->listPayments(
            $options['begin_time'],
            $options['end_time'],
            $options['sort_order'],
            $options['cursor'],
            $options['location_id'] ?? $this->locationId,
            $options['total'],
            $options['last_4'],
            $options['card_brand'])->getResult();
    }

    /**
     * Add a product to the order.
     *
     * @param  mixed  $product
     * @param  int  $quantity
     * @param  string  $currency
     * @return self
     *
     * @throws AlreadyUsedSquareProductException
     * @throws InvalidSquareOrderException
     * @throws MissingPropertyException
     */
    public function addProduct($product, int $quantity = 1, string $currency = 'USD')
    {
        //Product class
        $productClass = Constants::PRODUCT_NAMESPACE;

        try {
            if (is_a($product, $productClass)) {
                $productPivot = $this->productBuilder->addProductFromModel($this->orderCopy, $this->getOrder(), $product, $quantity, $currency);
            } else {
                $productPivot = $this->productBuilder->addProductFromArray($this->orderCopy, $this->getOrder(), $product, $quantity, $currency);
            }
            // Check if order already has this product
            if (! Util::hasProduct($this->orderCopy->products, $productPivot->product)) {
                $this->orderCopy->products->push($productPivot);
            } else {
                throw new AlreadyUsedSquareProductException('Product is already part of the order', 500);
            }
        } catch (MissingPropertyException $e) {
            throw new MissingPropertyException('Required field is missing', 500, $e);
        }

        return $this;
    }

    /**
     * @return CreateCustomerRequest|UpdateCustomerRequest
     */
    public function getCreateCustomerRequest()
    {
        return $this->createCustomerRequest;
    }

    /**
     * @param  CreateCustomerRequest  $createCustomerRequest
     * @return self
     */
    public function setCreateCustomerRequest(CreateCustomerRequest $createCustomerRequest)
    {
        $this->createCustomerRequest = $createCustomerRequest;

        return $this;
    }

    /**
     * @return CreateOrderRequest
     */
    public function getCreateOrderRequest()
    {
        return $this->createOrderRequest;
    }

    /**
     * @param  CreateOrderRequest  $createOrderRequest
     * @return self
     */
    public function setCreateOrderRequest(CreateOrderRequest $createOrderRequest)
    {
        $this->createOrderRequest = $createOrderRequest;

        return $this;
    }

    /**
     * @param  mixed  $customer
     * @return self
     *
     * @throws MissingPropertyException
     */
    public function setCustomer($customer)
    {
        $customerClass = Constants::CUSTOMER_NAMESPACE;

        if (is_a($customer, $customerClass)) {
            $this->customer = $this->customerBuilder->load($customer->toArray());
        } elseif (is_array($customer)) {
            $this->customer = $this->customerBuilder->load($customer);
        }

        if ($customer) {
            $customerRequest = $this->squareBuilder->buildCustomerRequest($this->customer);
            $this->setCreateCustomerRequest($customerRequest);
        }

        return $this;
    }

    /**
     * Setter for order.
     *
     * @param  mixed  $order
     * @param  string  $locationId
     * @param  string  $currency
     * @return self
     *
     * @throws InvalidSquareOrderException
     * @throws MissingPropertyException
     */
    public function setOrder($order, string $locationId, string $currency = 'USD')
    {
        //Order class
        $orderClass = config('nikolag.connections.square.order.namespace');

        if (! $order) {
            throw new MissingPropertyException('$order property is missing', 500);
        }
        if (! $locationId) {
            throw new MissingPropertyException('$locationId property is missing', 500);
        }

        $this->locationId = $locationId;
        $this->currency = $currency;

        if (is_a($order, $orderClass)) {
            $this->order = $order;
            $this->orderCopy = $this->orderBuilder->buildOrderCopyFromModel($order);
        } elseif (is_array($order)) {
            $this->order = $this->orderBuilder->buildOrderModelFromArray($order, new $orderClass());
            $this->orderCopy = $this->orderBuilder->buildOrderCopyFromArray($order);
        }

        return $this;
    }
}
