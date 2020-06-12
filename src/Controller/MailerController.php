<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use App\Service\QueueManager;

class MailerController extends AbstractController
{
    /**
     * @Route("/email")
     */
    public function sendEmail(MailerInterface $mailer, QueueManager $qm)
    {
        $email = (new TemplatedEmail())
            ->from(new Address('support@chesscheat.com', 'ChessCheat Support'))
            ->to('tutolmin@gmail.com')
            //->cc('cc@example.com')
            //->bcc('bcc@example.com')
            //->replyTo('fabien@example.com')
            //->priority(Email::PRIORITY_HIGH)
            ->subject('Time for Symfony Mailer!')
            // path of the Twig template to render
            ->htmlTemplate('emails/signup.html.twig')
            ->textTemplate('emails/signup.txt.twig')

            // pass variables (name => value) to the template
            ->context([
                'expiration_date' => new \DateTime('+7 days'),
                'username' => 'foo',
            ])
        ;

//        $mailer->send($email);

    $qm->notifyUser( 260235);


        // ...

        return new Response(
            '<html><body>Message sent!</body></html>'
        );
    }
}
?>
