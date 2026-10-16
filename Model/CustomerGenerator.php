<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use DateTimeImmutable;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Perspective\SalesDataGenerator\Model\ResourceModel\Backdater;

/**
 * Registers fake customers with a default address and backdates their registration.
 *
 * @phpstan-import-type Person from PersonGenerator
 */
class CustomerGenerator
{
    public const string EMAIL_DOMAIN = 'example.com';
    public const string PASSWORD = 'Password123!';

    public function __construct(
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly RegionInterfaceFactory $regionFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly GroupManagementInterface $groupManagement,
        private readonly EncryptorInterface $encryptor,
        private readonly PersonGenerator $personGenerator,
        private readonly RegionResolver $regionResolver,
        private readonly Backdater $backdater
    ) {
    }

    /**
     * Create a customer registered at the given time.
     *
     * @param StoreInterface $store
     * @param DateTimeImmutable $createdAt UTC time
     * @param string $tag unique tag the email ends with, so the customer can be found again
     * @return CustomerInterface
     * @throws LocalizedException
     */
    public function create(StoreInterface $store, DateTimeImmutable $createdAt, string $tag): CustomerInterface
    {
        $person = $this->personGenerator->create(self::EMAIL_DOMAIN, $tag);

        $customer = $this->customerFactory->create()
            ->setWebsiteId((int)$store->getWebsiteId())
            ->setStoreId((int)$store->getId())
            ->setGroupId((int)$this->groupManagement->getDefaultGroup((int)$store->getId())->getId())
            ->setFirstname($person['firstname'])
            ->setLastname($person['lastname'])
            ->setEmail($person['email'])
            ->setGender($person['gender'])
            ->setDob($person['dob'])
            ->setAddresses([$this->createAddress($person)]);

        $customer = $this->customerRepository->save($customer, $this->encryptor->getHash(self::PASSWORD, true));
        $this->backdater->backdateCustomer((int)$customer->getId(), $createdAt);

        return $customer;
    }

    /**
     * @param Person $person
     * @return AddressInterface
     */
    private function createAddress(array $person): AddressInterface
    {
        $data = $person['address'];
        $resolved = $this->regionResolver->resolve($data['country_id'], $data['region_code']);
        $region = $this->regionFactory->create()
            ->setRegionId($resolved['region_id'])
            ->setRegionCode($data['region_code'])
            ->setRegion($resolved['region']);

        return $this->addressFactory->create()
            ->setFirstname($person['firstname'])
            ->setLastname($person['lastname'])
            ->setCountryId($data['country_id'])
            ->setRegion($region)
            ->setRegionId($resolved['region_id'])
            ->setCity($data['city'])
            ->setPostcode($data['postcode'])
            ->setStreet([$data['street']])
            ->setTelephone($data['telephone'])
            ->setIsDefaultBilling(true)
            ->setIsDefaultShipping(true);
    }
}
