<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
 *  Copyright (C) 2024 Nexrem (https://github.com/meganukebmp)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);


namespace App\Services\InfoProviderSystem\Providers;

use App\Entity\Parts\ManufacturingStatus;
use App\Form\InfoProviderSystem\ProviderSelectType;
use App\Services\InfoProviderSystem\DTOs\FileDTO;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PriceDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LCSCProvider implements InfoProviderInterface
{

    private const ENDPOINT_URL = 'https://wmsc.lcsc.com/wmsc';

    public const DISTRIBUTOR_NAME = 'LCSC';

    public function __construct(private readonly HttpClientInterface $lcscClient)
    {

    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'LCSC',
            'description' => 'This provider uses the LCSC API to search for parts.',
            'url' => 'https://www.lcsc.com/',
            'disabled_help' => 'This provider is enabled by default'
        ];
    }

    public function getProviderKey(): string
    {
        return 'lcsc';
    }
    
    // This provider is always active
    public function isActive(): bool
    {
        return true;
    }

    /**
     * @param  string  $id
     * @return PartDetailDTO
     */
    private function queryDetail(string $id): PartDetailDTO
    {
        $response = $this->lcscClient->request('GET', self::ENDPOINT_URL . "/product/detail", [
            'query' => [
                'productCode' => $id,
            ],
        ]);

        $arr = $response->toArray();
        $product = $arr['result'] ?? null;

        if ($product == null)
        {
            throw new \RuntimeException('Could not find product code: ' . $id);
        }

        $dto = $this->getPartDetail($product);

        return $dto;
    }

    /**
     * @param  string  $term
     * @return PartDetailDTO[]
     */
    private function queryByTerm(string $term): array
    {
        $response = $this->lcscClient->request('GET', self::ENDPOINT_URL . "/search/global", [
            'query' => [
                'keyword' => $term,
            ],
        ]);

        $arr = $response->toArray();
        
        // Get products list
        $products = $arr['result']['productSearchResultVO']['productList'] ?? [];
        // Get product tip
        $tipProductCode = $arr['result']['tipProductDetailUrlVO']['productCode'] ?? null;

        $result = [];

        // LCSC does not display LCSC codes in the search, instead taking you directly to the
        // detailed product listing. It does so utilizing a product tip field.
        // If product tip exists and there are no products in the product list try a detail query
        if (count($products) == 0 && !($tipProductCode == null)) {
            $result[] = $this->queryDetail($tipProductCode);
        }

        foreach ($products as $product) {
            $result[] = $this->getPartDetail($product);
        }

        return $result;
    }

    /**
     * Takes a deserialized json object of the product and returns a PartDetailDTO
     * @param  array  $product
     * @return PartDetailDTO
     */
    function getPartDetail($product): PartDetailDTO
    {
        // Get product images in advance
        $product_images = $this->getProductImages($product['productImages'] ?? null);
        $product['productImageUrl'] = $product['productImageUrl'] ?? null;

        // If the product does not have a product image but otherwise has attached images, use the first one.
        if (count($product_images) > 0) {
            $product['productImageUrl'] = $product['productImageUrl'] ?? $product_images[0]->url;
        }

        // LCSC puts HTML in footprints and descriptions sometimes randomly
        $footprint = $product["encapStandard"] ?? null;
        if ($footprint != null) {
            $footprint = strip_tags($footprint);
        }

        $part_detail = new PartDetailDTO(
                provider_key: $this->getProviderKey(),
                provider_id: $product['productCode'],
                name: $product['productModel'],
                description: strip_tags($product['productIntroEn']),
                manufacturer: $product['brandNameEn'],
                mpn: $product['productModel'] ?? null,
                preview_image_url: $product['productImageUrl'],
                manufacturing_status: null,
                provider_url: $this->getProductShortURL($product['productCode']),
                datasheets: $this->getProductDatasheets($product['pdfUrl'] ?? null),
                parameters: $this->attributesToParameters($product['paramVOList'] ?? []),
                vendor_infos: $this->pricesToVendorInfo($product['productCode'], $this->getProductShortURL($product['productCode']), $product['productPriceList'] ?? []),
                footprint: $footprint,
                images: $product_images,
                mass: $product['weight'] ?? null,
            );

        return $part_detail;
    }

    /**
     * Converts the price array to a VendorInfoDTO array to be used in the PartDetailDTO
     * @param  string $sku
     * @param  string $url
     * @param  array  $prices
     * @return array
     */
    private function pricesToVendorInfo(string $sku, string $url, array $prices): array
    {
        $price_dtos = [];

        foreach ($prices as $price) {
            $price_dtos[] = new PriceDTO(
                minimum_discount_amount: $price['ladder'],
                price: $price['productPrice'],
                currency_iso_code: $this->getUsedCurrency($price['currencySymbol']),
                includes_tax: false,
            );
        }

        return [
            new PurchaseInfoDTO(
                distributor_name: self::DISTRIBUTOR_NAME,
                order_number: $sku,
                prices: $price_dtos,
                product_url: $url,
            )
        ];
    }
    
    /**
     * Converts LCSC currency symbol to an ISO code. Have not seen LCSC provide other currencies other than USD yet.
     * @param  string  $currency
     * @return string
     */
    private function getUsedCurrency(string $currency): string
    {
        //Decide based on the currency symbol
        return match ($currency) {
            'US$' => 'USD',
            default => throw new \RuntimeException('Unknown currency: ' . $currency)
        };
    }

    /**
     * Returns a valid LCSC product short URL from product code
     * @param  string  $product_code
     * @return string
     */
    private function getProductShortURL($product_code): string
    {
        return 'https://www.lcsc.com/product-detail/' . $product_code .'.html';
    }
  
    /**
     * Returns a product datasheet FileDTO array from a single pdf url
     * @param  string  $url
     * @return FileDTO[]
     */
    private function getProductDatasheets($url): array
    {
        if ($url == null) {
            return [];
        }

        return [new FileDTO($url, null)];
    }

    /**
     * Returns a FileDTO array with a list of product images
     * @param  array  $images
     * @return FileDTO[]
     */
    private function getProductImages($images): array
    {
        $result = [];
        foreach ($images as $image) {
            $result[] = new FileDTO($image);
        }

        return $result;
    }

    /**
     * @param  array|null  $attributes
     * @return ParameterDTO[]
     */
    private function attributesToParameters(?array $attributes): array
    {
        $result = [];

        foreach ($attributes as $attribute) {

            $result[] = ParameterDTO::parseValueField(name: $attribute['paramNameEn'], value: $attribute['paramValueEn'], unit: null, group: null);
        }

        return $result;
    }

    public function searchByKeyword(string $keyword): array
    {
        return $this->queryByTerm($keyword);
    }

    public function getDetails(string $id): PartDetailDTO
    {
        $tmp = $this->queryByTerm($id);
        if (count($tmp) === 0) {
            throw new \RuntimeException('No part found with ID ' . $id);
        }

        if (count($tmp) > 1) {
            throw new \RuntimeException('Multiple parts found with ID ' . $id);
        }

        return $tmp[0];
    }

    public function getCapabilities(): array
    {
        return [
            ProviderCapabilities::BASIC,
            ProviderCapabilities::PICTURE,
            ProviderCapabilities::DATASHEET,
            ProviderCapabilities::PRICE,
            ProviderCapabilities::FOOTPRINT,
        ];
    }
}