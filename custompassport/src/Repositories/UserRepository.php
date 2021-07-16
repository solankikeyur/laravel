<?php

namespace Laravel\CustomPassport\Repositories;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\HashManager;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Laravel\Passport\Bridge\User;
use Laravel\CustomPassport\Repositories\ClientRepository;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response AS BaseResponse;
use Laravel\CustomPassport\Http\Exception\OAuthServerException;

class UserRepository implements UserRepositoryInterface
{
    /**
     * The hasher implementation.
     *
     * @var \Illuminate\Hashing\HashManager
     */
    protected $hasher;

    protected $clientRepository;

    /**
     * Create a new repository instance.
     *
     * @param  \Illuminate\Hashing\HashManager  $hasher
     * @return void
     */
    public function __construct(HashManager $hasher, ClientRepository $clientRepository)
    {
        $this->hasher = $hasher->driver();
        $this->clientRepository = $clientRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserEntityByUserCredentials($username, $password, $grantType, ClientEntityInterface $clientEntity)
    {
        $clientId = $clientEntity->getIdentifier();
        $client = $this->clientRepository->find($clientId);

        $provider = config('auth.guards.api.provider');
        if($client && $client->provider) {
            $provider = config('auth.guards.'.$client->provider.'.provider');
        }

        if (is_null($model = config('auth.providers.'.$provider.'.model'))) {
            throw new RuntimeException('Unable to determine authentication model from configuration.');
        }

        if (method_exists($model, 'findForPassport')) {
            $user = (new $model)->findForPassport($username);
        } else {
            $user = (new $model)->where('email', $username)->first();
        }
        if (!$user) {
            return;
        } elseif ($user->status == config("core.disabled")) { // if user account disabled then do not allow to login
            throw OAuthServerException::customException(trans("passport::passport.messages.disabled_account"));
        } elseif (method_exists($user, 'validateForPassportPasswordGrant')) {
            if (! $user->validateForPassportPasswordGrant($password)) {
                return;
            }
        }
        elseif (! $this->hasher->check($password, $user->getAuthPassword())) {
            return;
        }
        
        return new User($user->getAuthIdentifier());
    }
}