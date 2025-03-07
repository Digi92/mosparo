<?php

namespace Mosparo\Controller;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Mosparo\Exception\AdminUserAlreadyExistsException;
use Mosparo\Exception\UserAlreadyExistsException;
use Mosparo\Form\PasswordFormType;
use Mosparo\Helper\ConfigHelper;
use Mosparo\Helper\SetupHelper;
use Mosparo\Kernel;
use PDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/setup")
 */
class SetupController extends AbstractController
{
    protected KernelInterface $kernel;

    protected SetupHelper $setupHelper;

    protected ConfigHelper $configHelper;

    public function __construct(KernelInterface $kernel, SetupHelper $setupHelper, ConfigHelper $configHelper)
    {
        $this->kernel = $kernel;
        $this->setupHelper = $setupHelper;
        $this->configHelper = $configHelper;
    }

    /**
     * @Route("/", name="setup_start")
     */
    public function start(): Response
    {
        return $this->render('setup/start.html.twig');
    }

    /**
     * @Route("/prerequisites", name="setup_prerequisites")
     */
    public function prerequisites(): Response
    {
        [ $meetPrerequisites, $prerequisites ] = $this->setupHelper->checkPrerequisites();

        return $this->render('setup/prerequisites.html.twig', [
            'meetPrerequisites' => $meetPrerequisites,
            'prerequisites' => $prerequisites
        ]);
    }

    /**
     * @Route("/database", name="setup_database")
     */
    public function database(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createFormBuilder([], ['translation_domain' => 'mosparo'])
            ->add('host', TextType::class, ['label' => 'setup.database.form.host'])
            ->add('port', TextType::class, ['label' => 'setup.database.form.port', 'required' => false, 'data' => 3306])
            ->add('database', TextType::class, ['label' => 'setup.database.form.database'])
            ->add('user', TextType::class, ['label' => 'setup.database.form.user'])
            ->add('password', PasswordType::class, ['label' => 'setup.database.form.password'])
            ->getForm();

        $form->handleRequest($request);
        $connected = false;
        $tablesExist = false;
        if ($form->isSubmitted() && $form->isValid()) {
            $data = [
                'database_driver' => 'pdo_mysql',
                'database_host' => $form->get('host')->getData(),
                'database_port' => $form->get('port')->getData(),
                'database_name' => $form->get('database')->getData(),
                'database_user' => $form->get('user')->getData(),
                'database_password' => $form->get('password')->getData()
            ];

            $tmpConnection = DriverManager::getConnection([
                'host' => $data['database_host'] ?? null,
                'port' => $data['database_port'] ?? null,
                'dbname' => $data['database_name'] ?? null,
                'user' => $data['database_user'] ?? null,
                'password' => $data['database_password'] ?? null,
                'driver' => $data['database_driver'],
            ]);

            try {
                $tmpConnection->connect();
                $connected = $tmpConnection->isConnected();

                $data['database_version'] = $tmpConnection->getNativeConnection()->getAttribute(PDO::ATTR_SERVER_VERSION);

                /** @var AbstractSchemaManager $schemaManager */
                $schemaManager = $tmpConnection->createSchemaManager();
                $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
                foreach ($metadata as $classMetadata) {
                    if ($schemaManager->tablesExist($classMetadata->getTableName())) {
                        $tablesExist = true;
                        break;
                    }
                }

                if ($connected && !$tablesExist) {
                    $this->configHelper->writeEnvironmentConfig($data);
                }
            } catch (ConnectionException $e) {
                $connected = false;
            }

            // Save the database connection in the session and continue with the setup
            if ($connected && !$tablesExist) {
                return $this->redirectToRoute('setup_other');
            }
        }

        return $this->render('setup/database.html.twig', [
            'form' => $form->createView(),
            'submitted' => $form->isSubmitted(),
            'connected' => $connected,
            'tablesExist' => $tablesExist
        ]);
    }

    /**
     * @Route("/other", name="setup_other")
     */
    public function other(Request $request): Response
    {
        $form = $this->createFormBuilder([], ['translation_domain' => 'mosparo'])
            ->add('name', TextType::class, ['label' => 'setup.other.form.name'])
            ->add('emailAddress', TextType::class, ['label' => 'setup.other.form.emailAddress'])
            ->add('password', PasswordFormType::class, [
                'label' => 'setup.other.form.password',
                'mapped' => false,
                'required' => true,
                'is_new_password' => false,
            ])
            ->getForm();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->configHelper->writeEnvironmentConfig([
                'mosparo_name' => $form->get('name')->getData(),
                'encryption_key' => $this->setupHelper->generateEncryptionKey(),
                'secret' => $this->setupHelper->generateEncryptionKey(),
            ]);

            $request->getSession()->set('setupUserEmailAddress', $form->get('emailAddress')->getData());
            $request->getSession()->set('setupUserPassword', $form->get('password')->get('plainPassword')->getData());

            return $this->redirectToRoute('setup_install');
        }

        return $this->render('setup/other.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/install", name="setup_install")
     */
    public function install(Request $request): Response
    {
        $session = $request->getSession();

        // Prepare database and execute the migrations
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput(array(
            'command' => 'doctrine:migrations:migrate',
            '-n'
        ));

        $output = new BufferedOutput();
        $application->run($input, $output);
        $output->fetch();

        // Create user
        try {
            $this->setupHelper->createUser($session->get('setupUserEmailAddress'), $session->get('setupUserPassword'));
        } catch (UserAlreadyExistsException|AdminUserAlreadyExistsException $e) {
            // Ignore this exception since the user exists, everything should be good.
        }

        $this->configHelper->writeEnvironmentConfig([
            'mosparo_installed' => true,
            'mosparo_installed_version' => Kernel::VERSION,
        ]);

        // Clear the cache after the installation
        $input = new ArrayInput(array(
            'command' => 'cache:clear',
            '-n'
        ));

        $output = new BufferedOutput();
        $application->run($input, $output);
        $output->fetch();

        return $this->render('setup/install.html.twig');
    }
}