<?php

namespace App\Services;

use App\Helpers\CheckSum;
use App\Helpers\Similarity;
use App\Http\Requests\TestAccessRequest;
use App\Models\IdentyResponse;
use App\Repositories\ConfirmationRepository;
use App\Repositories\IdentityFieldRepository;
use App\Services\IdentityProviders\Przelewy24Provider;
use App\Repositories\IdentityRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IdentityService
{

    private $providersList = [];
    private IdentityRepository $identityRepository;
    private IdentityFieldRepository $identityFieldRepository;
    private FieldService $fieldService;
    private ConfirmationRepository $confirmationRepository;

    public function __construct(
        Przelewy24Provider $przelewy24Provider,
        IdentityRepository $identityRepository,
        IdentityFieldRepository $identityFieldRepository,
        FieldService $fieldService,
        ConfirmationRepository $confirmationRepository
    ) {
        $this->providersList [] = $przelewy24Provider;
        $this->identityRepository = $identityRepository;
        $this->identityFieldRepository = $identityFieldRepository;
        $this->fieldService = $fieldService;
        $this->confirmationRepository = $confirmationRepository;
    }

    public function confirmIdentity(string $requestId, bool $isAuthorized): array
    {
        if (!$isAuthorized) {
            $identity = $this->identityRepository->findOneBy(['request_id' => $requestId]);
            Auth::loginUsingId($identity->user_id);
        }
        $identityStored = $this->identityRepository
            ->findByWith('request_id', $requestId, 'identitiesFields');
            $similarities = [];
            $confirmation_date = '';
            $fieldsList = $this->fieldService->getFieldsList();

            $similarities =  Similarity::calculate($identityStored->identitiesFields->toArray(), $fieldsList);

        $this->confirmationRepository->create(
            [
                'request_id' => $requestId,
                'reason' => 'verify'
            ]
        );
        return [
            'requestId' => $requestId,
            'fields' => $similarities,
            'confirmation_date' => $confirmation_date,
            'checkSum' => CheckSum::calculate($requestId),
            'url_confirm' => $identityStored->url_confirm
        ];
    }

    public function testAccess(TestAccessRequest $request): bool
    {
        return hash_equals(CheckSum::calculate($request['request_id']), $request['check_sum']);
    }

    public function register(array $identity): array
    {
        $identity['sessionId'] = Str::random(40);
        $identity['user_id'] = Auth::user()->id;

        $check_sum = CheckSum::calculate($identity['request_id']);
        $identityData = $identity['data'];
        unset($identity['data'], $identity['check_sum']);

        $identity = $this->identityRepository->create($identity);

        $fieldsDictionary = $this->fieldService->getFieldsList();

        foreach ($identityData as $field) {
            if (isset($fieldsDictionary[$field['fieldName']])) {
                $this->identityFieldRepository->create([
                    'identity_id' => $identity['id'],
                    'field_id' => $fieldsDictionary[$field['fieldName']],
                    'declared_value' => $field['fieldValue']
                ]);
            }
        }

        return ['id' => "{$identity['sessionId']}",
                    'check_sum' => "{$check_sum}"
        ];
    }

    public function getProvidersList(): array
    {
        $ret = [];
        foreach ($this->providersList as $provider) {
            $ret[] = ['name' => class_basename($provider), 'logo' => $provider->logo];
        }
        return $ret;
    }

    public function confirm($request): IdentyResponse
    {
        $identity = $this->identityRepository
            ->findByWith('email', $request['email'], 'identyResponse');

        return $identity->identyResponse;
    }
}
