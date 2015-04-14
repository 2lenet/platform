<?php

namespace Oro\Bundle\EmailBundle\Tests\Unit\Mailer;

use Symfony\Component\PropertyAccess\PropertyAccess;

use Oro\Bundle\EmailBundle\Entity\EmailFolder;
use Oro\Bundle\EmailBundle\Entity\InternalEmailOrigin;
use Oro\Bundle\EmailBundle\Tools\EmailAddressHelper;
use Oro\Bundle\EmailBundle\Form\Model\Email;
use Oro\Bundle\EmailBundle\Mailer\Processor;
use Oro\Bundle\EmailBundle\Tests\Unit\Fixtures\Entity\TestUser;
use Oro\Bundle\UserBundle\Entity\User;
use Oro\Bundle\EmailBundle\Model\FolderType;

/**
 * Class ProcessorTest
 *
 * @package Oro\Bundle\EmailBundle\Tests\Unit\Mailer
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class ProcessorTest extends \PHPUnit_Framework_TestCase
{
    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $em;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $doctrineHelper;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $mailer;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $emailEntityBuilder;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $emailOwnerProvider;

    /** @var  \PHPUnit_Framework_MockObject_MockObject */
    protected $emailActivityManager;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $dispatcher;

    /** @var Processor */
    protected $emailProcessor;

    protected function setUp()
    {
        $this->em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->doctrineHelper = $this->getMockBuilder('Oro\Bundle\EntityBundle\ORM\DoctrineHelper')
            ->disableOriginalConstructor()
            ->getMock();
        $this->mailer = $this->getMockBuilder('\Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $this->emailEntityBuilder = $this->getMockBuilder('Oro\Bundle\EmailBundle\Builder\EmailEntityBuilder')
            ->disableOriginalConstructor()
            ->getMock();
        $this->emailOwnerProvider = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\Provider\EmailOwnerProvider')
            ->disableOriginalConstructor()
            ->getMock();
        $this->dispatcher = $this->getMockBuilder('Symfony\Component\EventDispatcher\EventDispatcherInterface')
            ->disableOriginalConstructor()
            ->getMock();
        $this->emailActivityManager =
            $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\Manager\EmailActivityManager')
                ->disableOriginalConstructor()
                ->getMock();

        $this->doctrineHelper->expects($this->any())
            ->method('getEntityManager')
            ->with('OroEmailBundle:Email')
            ->will($this->returnValue($this->em));

        $this->emailProcessor = new Processor(
            $this->doctrineHelper,
            $this->mailer,
            new EmailAddressHelper(),
            $this->emailEntityBuilder,
            $this->emailOwnerProvider,
            $this->emailActivityManager,
            $this->dispatcher
        );
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Sender can not be empty
     */
    public function testProcessEmptyFromException()
    {
        $this->mailer->expects($this->never())
            ->method('createMessage');
        $this->mailer->expects($this->never())
            ->method('send');

        $this->emailProcessor->process($this->createEmailModel([]));
    }

    /**
     * @dataProvider invalidModelDataProvider
     */
    public function testProcessEmptyToException($data, $exception, $exceptionMessage)
    {
        $this->mailer->expects($this->never())
            ->method('createMessage');
        $this->mailer->expects($this->never())
            ->method('send');

        $this->setExpectedException($exception, $exceptionMessage);
        $this->emailProcessor->process($this->createEmailModel($data));
    }

    public function invalidModelDataProvider()
    {
        return [
            [[], '\InvalidArgumentException', 'Sender can not be empty'],
            [['from' => 'test@test.com'], '\InvalidArgumentException', 'Recipient can not be empty'],
        ];
    }

    /**
     * @expectedException \Swift_SwiftException
     * @expectedExceptionMessage An email was not delivered.
     */
    public function testProcessSendFailException()
    {
        $message = $this->getMockForAbstractClass('\Swift_Message');
        $this->mailer->expects($this->once())
            ->method('createMessage')
            ->will($this->returnValue($message));
        $this->mailer->expects($this->once())
            ->method('send')
            ->with($message)
            ->will($this->returnValue(false));

        $model = $this->createEmailModel(
            [
                'from'    => 'test@test.com',
                'to'      => ['test2@test.com'],
                'subject' => 'test',
                'body'    => 'test body'
            ]
        );
        $this->emailProcessor->process($model);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage The $addresses argument must be a string or a list of strings (array or Iterator)
     */
    public function testProcessAddressException()
    {
        $message = $this->getMockForAbstractClass('\Swift_Message');
        $this->mailer->expects($this->once())
            ->method('createMessage')
            ->will($this->returnValue($message));
        $this->mailer->expects($this->never())
            ->method('send');

        $model = $this->createEmailModel(
            [
                'from' => new \stdClass(),
                'to' => [new \stdClass()],
            ]
        );
        $this->emailProcessor->process($model);
    }

    /**
     * @dataProvider messageDataProvider
     * @param array $data
     * @param array $expectedMessageData
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testProcess($data, $expectedMessageData)
    {
        $message = $this->getMockBuilder('\Swift_Message')
            ->setMethods(['setDate', 'setFrom', 'setTo',  'setCc',  'setBcc', 'setSubject', 'setBody'])
            ->getMockForAbstractClass();
        $message->expects($this->once())
            ->method('setDate');
        $message->expects($this->once())
            ->method('setFrom')
            ->with($expectedMessageData['from']);
        $message->expects($this->once())
            ->method('setTo')
            ->with($expectedMessageData['to']);
        $message->expects($this->once())
            ->method('setCc')
            ->with($expectedMessageData['cc']);
        $message->expects($this->once())
            ->method('setBcc')
            ->with($expectedMessageData['bcc']);
        $message->expects($this->once())
            ->method('setSubject')
            ->with($expectedMessageData['subject']);
        $message->expects($this->once())
            ->method('setBody')
            ->with(
                $expectedMessageData['body'],
                isset($expectedMessageData['type']) ? $expectedMessageData['type'] : 'text/plain'
            );

        $this->mailer->expects($this->once())
            ->method('createMessage')
            ->will($this->returnValue($message));
        $this->mailer->expects($this->once())
            ->method('send')
            ->with($message)
            ->will($this->returnValue(true));

        $origin = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\EmailOrigin')
            ->disableOriginalConstructor()
            ->getMock();
        $folder = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\EmailFolder')
            ->disableOriginalConstructor()
            ->getMock();
        $origin->expects($this->once())
            ->method('getFolder')
            ->with(FolderType::SENT)
            ->will($this->returnValue($folder));

        $emailOriginRepo = $this->getMockBuilder('Doctrine\ORM\EntityRepository')
            ->disableOriginalConstructor()
            ->getMock();
        $emailOriginRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['internalName' => InternalEmailOrigin::BAP])
            ->will($this->returnValue($origin));
        $this->em->expects($this->once())
            ->method('getRepository')
            ->with('OroEmailBundle:InternalEmailOrigin')
            ->will($this->returnValue($emailOriginRepo));

        $this->emailEntityBuilder->expects($this->once())
            ->method('setOrigin')
            ->with($origin);

        $email = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\Email')
            ->disableOriginalConstructor()
            ->getMock();
        $this->emailEntityBuilder->expects($this->once())
            ->method('email')
            ->with(
                $data['subject'],
                $data['from'],
                $data['to'],
                $this->isInstanceOf('DateTime'),
                $this->isInstanceOf('DateTime'),
                $this->isInstanceOf('DateTime'),
                \Oro\Bundle\EmailBundle\Entity\Email::NORMAL_IMPORTANCE,
                $data['cc'],
                $data['bcc']
            )
            ->will($this->returnValue($email));

        $body = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\EmailBody')
            ->disableOriginalConstructor()
            ->getMock();
        $this->emailEntityBuilder->expects($this->once())
            ->method('body')
            ->with(
                $expectedMessageData['body'],
                isset($data['type']) && $data['type'] === 'html' ? true : false,
                true
            )
            ->will($this->returnValue($body));

        $batch = $this->getMock('Oro\Bundle\EmailBundle\Builder\EmailEntityBatchInterface');
        $this->emailEntityBuilder->expects($this->once())
            ->method('getBatch')
            ->will($this->returnValue($batch));
        $batch->expects($this->once())
            ->method('persist')
            ->with($this->identicalTo($this->em));
        $this->em->expects($this->once())->method('flush');

        if (!empty($data['entityClass']) && !empty($data['entityClass'])) {
            $targetEntity = new TestUser();
            $this->doctrineHelper->expects($this->exactly(0))
                ->method('getEntity')
                ->with($data['entityClass'], $data['entityId'])
                ->will($this->returnValue($targetEntity));
            $this->emailActivityManager->expects($this->exactly(0))
                ->method('addAssociation')
                ->with($this->identicalTo($email), $this->identicalTo($targetEntity));
        } else {
        }

        $model = $this->createEmailModel($data);
        $this->assertSame($email, $this->emailProcessor->process($model));
    }

    public function messageDataProvider()
    {
        return [
            [
                [
                    'from' => 'from@test.com',
                    'to' => ['to@test.com'],
                    'cc' => ['Cc <cc@test.com>'],
                    'bcc' => ['Bcc <bcc@test.com>'],
                    'subject' => 'subject',
                    'body' => 'body'
                ],
                [
                    'from' => ['from@test.com'],
                    'to' => ['to@test.com'],
                    'cc' => ['cc@test.com' => 'Cc'],
                    'bcc' => ['bcc@test.com' => 'Bcc'],
                    'subject' => 'subject',
                    'body' => 'body'
                ]
            ],
            [
                [
                    'from' => 'from@test.com',
                    'to' => ['to@test.com'],
                    'cc' => [],
                    'bcc' => [],
                    'subject' => 'subject',
                    'body' => 'body',
                    'type' => 'html'
                ],
                [
                    'from' => ['from@test.com'],
                    'to' => ['to@test.com'],
                    'cc' => [],
                    'bcc' => [],
                    'subject' => 'subject',
                    'body' => 'body',
                    'type' => 'text/html'
                ]
            ],
            [
                [
                    'from' => 'Test <from@test.com>',
                    'to' => ['To <to@test.com>', 'to2@test.com'],
                    'cc' => ['Cc3 <cc3@test.com>', 'cc4@test.com'],
                    'bcc' => [],
                    'subject' => 'subject',
                    'body' => 'body'
                ],
                [
                    'from' => ['from@test.com' => 'Test'],
                    'to' => ['to@test.com' => 'To', 'to2@test.com'],
                    'cc' => ['cc3@test.com' => 'Cc3', 'cc4@test.com'],
                    'bcc' => [],
                    'subject' => 'subject',
                    'body' => 'body'
                ]
            ],
            [
                [
                    'from' => 'from@test.com',
                    'to' => ['to1@test.com', 'to1@test.com', 'to2@test.com'],
                    'cc' => [],
                    'bcc' => ['bcc3@test.com', 'bcc4@test.com'],
                    'subject' => 'subject',
                    'body' => 'body',
                    'entityClass' => 'Entity\Target',
                    'entityId' => 123
                ],
                [
                    'from' => ['from@test.com'],
                    'to' => ['to1@test.com', 'to1@test.com', 'to2@test.com'],
                    'cc' => [],
                    'bcc' => ['bcc3@test.com', 'bcc4@test.com'],
                    'subject' => 'subject',
                    'body' => 'body'
                ]
            ],
        ];
    }

    public function testCreateUserInternalOrigin()
    {
        $processor = new \ReflectionClass('Oro\Bundle\EmailBundle\Mailer\Processor');
        $method = $processor->getMethod('createUserInternalOrigin');
        $method->setAccessible(true);

        $origin = $method->invokeArgs($this->emailProcessor, [$this->getTestUser()]);

        $this->assertEquals($this->getTestOrigin(), $origin);
    }

    public function testProcessOwnerUserWithoutOrigin()
    {
        $this->processWithOwner($this->getTestUser());
    }

    public function testProcessOwnerUserWithOrigin()
    {
        $user   = $this->getTestUser();
        $origin = $this->getTestOrigin();

        $user->addEmailOrigin($origin);

        $this->processWithOwner($user, true);
    }

    /**
     * @param      $user
     * @param bool $withOrigin
     */
    protected function processWithOwner($user, $withOrigin = false)
    {
        $message = $this->getMockForAbstractClass('\Swift_Message');

        $this->mailer->expects($this->once())
            ->method('createMessage')
            ->will($this->returnValue($message));
        $this->mailer->expects($this->once())
            ->method('send')
            ->with($message)
            ->will($this->returnValue(true));

        $this->emailOwnerProvider->expects($this->once())
            ->method('findEmailOwner')
            ->with($this->em, 'test_user@test.com')
            ->will($this->returnValue($user));

        $email = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\Email')
            ->disableOriginalConstructor()
            ->getMock();
        $this->emailEntityBuilder->expects($this->once())
            ->method('email')
            ->with('test', 'Test User <test_user@test.com>', ['test2@test.com'])
            ->will($this->returnValue($email));
        $body = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\EmailBody')
            ->disableOriginalConstructor()
            ->getMock();
        $this->emailEntityBuilder->expects($this->once())
            ->method('body')
            ->with('test body', false, true)
            ->will($this->returnValue($body));

        $batch = $this->getMock('Oro\Bundle\EmailBundle\Builder\EmailEntityBatchInterface');
        $this->emailEntityBuilder->expects($this->once())
            ->method('getBatch')
            ->will($this->returnValue($batch));
        $batch->expects($this->once())
            ->method('persist')
            ->with($this->identicalTo($this->em));
        $this->em->expects($this->once())
            ->method('flush');

        if (!$withOrigin) {
            $this->emailProcessor = $this->getMockBuilder('Oro\Bundle\EmailBundle\Mailer\Processor')
                ->setConstructorArgs(
                    [
                        $this->doctrineHelper,
                        $this->mailer,
                        new EmailAddressHelper(),
                        $this->emailEntityBuilder,
                        $this->emailOwnerProvider,
                        $this->emailActivityManager,
                        $this->dispatcher
                    ]
                )
                ->setMethods(['createUserInternalOrigin'])
                ->getMock();

            $this->emailProcessor->expects($this->once())
                ->method('createUserInternalOrigin')
                ->with($user)
                ->will($this->returnValue($this->getTestOrigin()));
        }

        $model = $this->createEmailModel(
            [
                'from' => 'Test User <test_user@test.com>',
                'to' => ['test2@test.com'],
                'subject' => 'test',
                'body' => 'test body'
            ]
        );

        $this->emailProcessor->process($model);
    }

    protected function getTestUser()
    {
        $user = new User();
        $user->setId(1);
        $user->setEmail('test_user@test.com');
        $user->setSalt('1fqvkjskgry8s8cs400840c0ok8ggck');

        return $user;
    }

    protected function getTestOrigin()
    {
        $outboxFolder = new EmailFolder();
        $outboxFolder
            ->setType(FolderType::SENT)
            ->setName(FolderType::SENT)
            ->setFullName(FolderType::SENT);

        $origin = new InternalEmailOrigin();
        $origin
            ->setName('BAP_User_1')
            ->addFolder($outboxFolder);

        return $origin;
    }

    protected function createEmailModel($data)
    {
        $email = new Email();
        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        foreach ($data as $key => $value) {
            $propertyAccessor->setValue($email, $key, $value);
        }
        return $email;
    }
}
