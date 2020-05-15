<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * UploadGameTaskVoter.
 */
class UserVoter extends Voter
{
    public const CAN_UPLOAD = 'can_upload';

    /** {@inheritDoc} */
    protected function supports($attribute, $subject): bool
    {
        if (!in_array($attribute, [self::CAN_UPLOAD], true)) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \RuntimeException
     */
    protected function voteOnAttribute($attribute, $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        switch ($attribute) {
            case self::CAN_UPLOAD:
                return $user->isCanUpload();
                break;

            default:
                throw new \RuntimeException(sprintf('Unknown attribute %s for %s', $attribute, __CLASS__));
                break;
        }
    }
}



