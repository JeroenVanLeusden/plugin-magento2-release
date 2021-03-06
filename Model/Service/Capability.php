<?php

namespace DHLParcel\Shipping\Model\Service;

use DHLParcel\Shipping\Helper\Data;
use DHLParcel\Shipping\Model\Api\Connector;
use DHLParcel\Shipping\Model\Cache\Api as ApiCache;
use DHLParcel\Shipping\Model\Data\Capability\OptionFactory;
use DHLParcel\Shipping\Model\Data\Capability\ProductFactory;
use DHLParcel\Shipping\Model\Data\Api\Request\CapabilityCheckFactory;
use DHLParcel\Shipping\Model\Data\Api\Response\CapabilityFactory;

class Capability
{
    protected $helper;
    protected $apiCache;
    protected $connector;
    protected $optionFactory;
    protected $productFactory;
    protected $capabilityCheckFactory;
    protected $capabilityFactory;

    public function __construct(
        Data $helper,
        ApiCache $apiCache,
        Connector $connector,
        OptionFactory $optionFactory,
        ProductFactory $productFactory,
        CapabilityCheckFactory $capabilityCheckFactory,
        CapabilityFactory $capabilityFactory
    ) {
        $this->helper = $helper;
        $this->apiCache = $apiCache;
        $this->connector = $connector;
        $this->optionFactory = $optionFactory;
        $this->productFactory = $productFactory;
        $this->capabilityCheckFactory = $capabilityCheckFactory;
        $this->capabilityFactory = $capabilityFactory;
    }

    public function getOptions($toCountry = '', $toPostalCode = '', $toBusiness = false, $requestOptions = [])
    {
        $capabilityCheck = $this->createCapabilityCheck($toCountry, $toPostalCode, $toBusiness, $requestOptions);
        $capabilities = $this->sendRequest($capabilityCheck);

        $options = [];
        foreach ($capabilities as $capability) {
            if (!isset($capability->parcelType->key)) {
                continue;
            }

            if (empty($capability->options)) {
                continue;
            }

            foreach ($capability->options as $responseOption) {
                if (!$responseOption->key) {
                    continue;
                }

                if (!isset($options[$responseOption->key])) {
                    /** @var \DHLParcel\Shipping\Model\Data\Capability\Option $option */
                    $option = $this->optionFactory->create();
                    $option->key = $responseOption->key;
                    $option->type = [];
                    $option->exclusions = [];
                    // Set exclusions only once
                    if (isset($responseOption->exclusions) && is_array($responseOption->exclusions)) {
                        foreach ($responseOption->exclusions as $exclusion) {
                            $option->exclusions[] = $exclusion->key;
                        }
                    }
                    $options[$responseOption->key] = $option;
                } else {
                    /** @var \DHLParcel\Shipping\Model\Data\Capability\Option $option */
                    $option = $options[$responseOption->key];
                }

                // Add size to the stack of sizes, per service option
                $option->type[] = $capability->parcelType->key;
                $options[$responseOption->key] = $option;
            }
        }

        // Change to a full array
        $options = array_map(function ($value) {
            return $value->toArray();
        }, $options);

        return $options;
    }

    public function getSizes($toCountry = '', $toPostalCode = '', $toBusiness = false, $requestOptions = [])
    {
        $capabilityCheck = $this->createCapabilityCheck($toCountry, $toPostalCode, $toBusiness, $requestOptions);
        $capabilities = $this->sendRequest($capabilityCheck);

        $products = [];
        foreach ($capabilities as $capability) {
            if (!isset($capability->parcelType->key)) {
                continue;
            }

            if (!isset($capability->product->key)) {
                continue;
            }

            // Skip if already parsed
            if (isset($products[$capability->parcelType->key])) {
                continue;
            }

            /** @var \DHLParcel\Shipping\Model\Data\Capability\Product $product */
            $product = $this->productFactory->create(['automap' => $capability->parcelType->toArray()]);
            $product->productKey = $capability->product->key;

            $products[$capability->parcelType->key] = $product->toArray();
        }

        array_multisort(array_column($products, 'maxWeightKg'), SORT_ASC, $products);

        return $products;
    }

    /**
     * @param $toCountry
     * @param $toPostalCode
     * @param $toBusiness
     * @param $options
     * @return \DHLParcel\Shipping\Model\Data\Api\Request\CapabilityCheck
     */
    protected function createCapabilityCheck($toCountry, $toPostalCode, $toBusiness, $requestOptions)
    {
        $fromCountry = $this->helper->getConfigData('shipper/country_code');
        $fromPostalCode = $this->helper->getConfigData('shipper/postal_code');
        $accountNumber = $this->helper->getConfigData('api/account_id');

        /** @var \DHLParcel\Shipping\Model\Data\Api\Request\CapabilityCheck $capabilityCheck */
        $capabilityCheck = $this->capabilityCheckFactory->create();
        $capabilityCheck->fromCountry = $fromCountry;
        $capabilityCheck->fromPostalCode = strtoupper($fromPostalCode);
        $capabilityCheck->toCountry = $toCountry ?: $fromCountry;
        $capabilityCheck->toBusiness = $toBusiness ? 'true' : 'false';
        $capabilityCheck->accountNumber = $accountNumber;

        if ($toPostalCode !== '') {
            $capabilityCheck->toPostalCode = strtoupper($toPostalCode);
        }

        if (is_array($requestOptions) && count($requestOptions)) {
            $capabilityCheck->option = implode(',', $requestOptions);
        }

        return $capabilityCheck;
    }

    /**
     * @param \DHLParcel\Shipping\Model\Data\Api\Request\CapabilityCheck $capabilityCheck
     * @return \DHLParcel\Shipping\Model\Data\Api\Response\Capability[]
     */
    protected function sendRequest($capabilityCheck)
    {
        $cacheKey = $this->apiCache->createKey('capabilities', $capabilityCheck->toArray(true));
        $json = $this->apiCache->load($cacheKey);

        if ($json === false) {
            $response = $this->connector->get('capabilities/business', $capabilityCheck->toArray(true));
            if (!empty($response)) {
                $this->apiCache->save(json_encode($response), $cacheKey, [], 3600);
            }
        } else {
            $response = json_decode($json, true);
        }

        $capabilities = [];
        if ($response && is_array($response)) {
            foreach ($response as $entry) {
                $capabilities[] = $this->capabilityFactory->create(['automap' => $entry]);
            }
        }

        return $capabilities;
    }
}
