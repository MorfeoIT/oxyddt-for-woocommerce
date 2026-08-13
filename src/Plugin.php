<?php
/**
 * Wiring.
 *
 * @package Oxysoft\OxyDDT
 */

declare(strict_types=1);

namespace Oxysoft\OxyDDT;

use Oxysoft\OxyDDT\Admin\DocumentActions;
use Oxysoft\OxyDDT\Admin\EditScreen;
use Oxysoft\OxyDDT\Admin\Menu;
use Oxysoft\OxyDDT\Admin\NumberingScreen;
use Oxysoft\OxyDDT\Admin\OrderMetabox;
use Oxysoft\OxyDDT\Admin\RegisterScreen;
use Oxysoft\OxyDDT\Admin\Screen;
use Oxysoft\OxyDDT\Admin\SettingsScreen;
use Oxysoft\OxyDDT\Audit\AuditLog;
use Oxysoft\OxyDDT\Domain\DocumentRepositoryInterface;
use Oxysoft\OxyDDT\Domain\SequenceRepositoryInterface;
use Oxysoft\OxyDDT\Issuing\Issuer;
use Oxysoft\OxyDDT\Persistence\SequenceRepository;
use Oxysoft\OxyDDT\Infrastructure\ClockInterface;
use Oxysoft\OxyDDT\Infrastructure\Container;
use Oxysoft\OxyDDT\Infrastructure\Migrator;
use Oxysoft\OxyDDT\Infrastructure\Registrable;
use Oxysoft\OxyDDT\Infrastructure\SystemClock;
use Oxysoft\OxyDDT\Infrastructure\Templates;
use Oxysoft\OxyDDT\Mail\DocumentMailer;
use Oxysoft\OxyDDT\Pdf\DocumentHtml;
use Oxysoft\OxyDDT\Pdf\DompdfRenderer;
use Oxysoft\OxyDDT\Pdf\PdfRendererInterface;
use Oxysoft\OxyDDT\Pdf\PdfService;
use Oxysoft\OxyDDT\Pdf\PdfStore;
use Oxysoft\OxyDDT\Persistence\DocumentRepository;
use Oxysoft\OxyDDT\Security\Capabilities;
use Oxysoft\OxyDDT\Settings\Settings;
use Oxysoft\OxyDDT\WooCommerce\DocumentFactory;
use Oxysoft\OxyDDT\WooCommerce\OrderFulfilment;

/**
 * Builds the object graph and registers the hooks.
 *
 * The container exists for one reason: OxyDDT PRO, and any third-party add-on,
 * has to be able to replace a service — the numbering sequence above all —
 * rather than edit a core file. Everything it does beyond that is deliberately
 * absent.
 */
final class Plugin {

	/**
	 * Service identifier of the settings store.
	 */
	public const SETTINGS = 'settings';

	/**
	 * Service identifier of the clock.
	 */
	public const CLOCK = 'clock';

	/**
	 * Service identifier of the audit log.
	 */
	public const AUDIT = 'audit.log';

	/**
	 * Service identifier of the document store.
	 */
	public const DOCUMENTS = 'documents';

	/**
	 * Service identifier of the order-to-draft factory.
	 */
	public const DOCUMENT_FACTORY = 'documents.factory';

	/**
	 * Service identifier of the outstanding-quantities service.
	 */
	public const FULFILMENT = 'documents.fulfilment';

	/**
	 * Service identifier of the numbering counter.
	 */
	public const SEQUENCES = 'documents.sequences';

	/**
	 * Service identifier of the issuer.
	 */
	public const ISSUER = 'documents.issuer';

	/**
	 * Service identifier of the register tab.
	 */
	public const REGISTER_SCREEN = 'admin.register';

	/**
	 * Service identifier of the numbering tab.
	 */
	public const NUMBERING_SCREEN = 'admin.numbering';

	/**
	 * Service identifier of the template loader.
	 */
	public const TEMPLATES = 'templates';

	/**
	 * Service identifier of the PDF engine.
	 */
	public const PDF_RENDERER = 'pdf.renderer';

	/**
	 * Service identifier of the PDF service.
	 */
	public const PDF = 'pdf';

	/**
	 * Service identifier of the mailer.
	 */
	public const MAILER = 'mail';

	/**
	 * Service identifier of the download, print and send endpoints.
	 */
	public const DOCUMENT_ACTIONS = 'admin.actions';

	/**
	 * Service identifier of the admin page that holds the tabs.
	 */
	public const SCREEN = 'admin.screen';

	/**
	 * Service identifier of the screen that prepares a delivery note.
	 */
	public const EDIT_SCREEN = 'admin.edit';

	/**
	 * Service identifier of the box on the order screen.
	 */
	public const ORDER_METABOX = 'admin.order-metabox';

	/**
	 * Service identifier of the settings tab.
	 */
	public const SETTINGS_SCREEN = 'admin.settings';

	/**
	 * Service identifier of the admin menu.
	 */
	public const MENU = 'admin.menu';

	/**
	 * Start the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		$migrator = new Migrator();

		if ( $migrator->needs_migration() ) {
			$migrator->migrate();
		}

		Capabilities::ensure_granted();

		// Asked again, later, for one case: WooCommerce installing itself in this
		// same request — a bulk activation, or a shop's very first run. Its roles
		// do not exist yet at plugins_loaded, so the grant above declines to write
		// its version option and would otherwise wait for the next request, leaving
		// a shop manager who cannot open anything. When there is nothing to do this
		// costs one get_option().
		add_action( 'init', array( Capabilities::class, 'ensure_granted' ), 20 );

		$container = $this->container();

		/**
		 * Fires after OxyDDT has registered its own services and before any of
		 * them is resolved.
		 *
		 * This is the extension point add-ons use: register a new service, or
		 * overwrite one of OxyDDT's own with a compatible implementation. Nothing
		 * has been instantiated yet, so a replacement here is total.
		 *
		 * @since 0.1.0
		 *
		 * @param Container $container The service container.
		 */
		do_action( 'oxyddt_register_services', $container );

		foreach ( $container->ids() as $id ) {
			$service = $container->get( $id );

			if ( $service instanceof Registrable ) {
				$service->register();
			}
		}

		/**
		 * Fires when OxyDDT has started and every service has registered.
		 *
		 * @since 0.1.0
		 *
		 * @param Container $container The service container.
		 */
		do_action( 'oxyddt_init', $container );
	}

	/**
	 * The services OxyDDT itself provides.
	 *
	 * Factories are closures, so declaring a service costs nothing until
	 * something asks for it.
	 *
	 * @return Container
	 */
	private function container(): Container {
		$container = new Container();

		$container->set(
			self::SETTINGS,
			static fn (): Settings => new Settings()
		);

		$container->set(
			self::CLOCK,
			static fn (): SystemClock => new SystemClock()
		);

		$container->set(
			self::AUDIT,
			static fn ( Container $c ): AuditLog => new AuditLog(
				$c->get_typed( self::CLOCK, ClockInterface::class )
			)
		);

		$container->set(
			self::DOCUMENTS,
			static fn ( Container $c ): DocumentRepository => new DocumentRepository(
				$c->get_typed( self::CLOCK, ClockInterface::class )
			)
		);

		$container->set(
			self::DOCUMENT_FACTORY,
			static fn ( Container $c ): DocumentFactory => new DocumentFactory(
				$c->get_typed( self::SETTINGS, Settings::class ),
				$c->get_typed( self::CLOCK, ClockInterface::class )
			)
		);

		$container->set(
			self::FULFILMENT,
			static fn ( Container $c ): OrderFulfilment => new OrderFulfilment(
				$c->get_typed( self::DOCUMENTS, DocumentRepositoryInterface::class )
			)
		);

		$container->set(
			self::SEQUENCES,
			static fn ( Container $c ): SequenceRepository => new SequenceRepository(
				$c->get_typed( self::CLOCK, ClockInterface::class )
			)
		);

		$container->set(
			self::ISSUER,
			static fn ( Container $c ): Issuer => new Issuer(
				$c->get_typed( self::DOCUMENTS, DocumentRepositoryInterface::class ),
				$c->get_typed( self::SEQUENCES, SequenceRepositoryInterface::class ),
				$c->get_typed( self::SETTINGS, Settings::class ),
				$c->get_typed( self::CLOCK, ClockInterface::class ),
				$c->get_typed( self::AUDIT, AuditLog::class )
			)
		);

		$container->set(
			self::TEMPLATES,
			static fn (): Templates => new Templates()
		);

		$container->set(
			self::PDF_RENDERER,
			static fn (): DompdfRenderer => new DompdfRenderer()
		);

		$container->set(
			self::PDF,
			static fn ( Container $c ): PdfService => new PdfService(
				$c->get_typed( self::PDF_RENDERER, PdfRendererInterface::class ),
				new PdfStore(),
				new DocumentHtml( $c->get_typed( self::TEMPLATES, Templates::class ) ),
				$c->get_typed( self::DOCUMENTS, DocumentRepositoryInterface::class ),
				$c->get_typed( self::AUDIT, AuditLog::class )
			)
		);

		$container->set(
			self::MAILER,
			static fn ( Container $c ): DocumentMailer => new DocumentMailer(
				$c->get_typed( self::PDF, PdfService::class ),
				$c->get_typed( self::AUDIT, AuditLog::class )
			)
		);

		// Not admin-only: the endpoints answer on admin-post.php, and the hook
		// that archives a PDF when a document is issued has to be there wherever
		// the issuing happened — a REST call, WP-CLI, or a screen.
		$container->set(
			self::DOCUMENT_ACTIONS,
			static fn ( Container $c ): DocumentActions => new DocumentActions(
				$c->get_typed( self::DOCUMENTS, DocumentRepositoryInterface::class ),
				$c->get_typed( self::PDF, PdfService::class ),
				$c->get_typed( self::MAILER, DocumentMailer::class )
			)
		);

		// Admin-only services are not built on a front-end request at all. The
		// hooks they add would never fire there, and the objects would be built
		// for nothing on every page view.
		if ( is_admin() ) {
			$container->set(
				self::SCREEN,
				static fn (): Screen => new Screen()
			);

			// Declared after the page and before the menu, which is also the order
			// the tabs appear in. The register arrives in sprint 6 and goes first.
			$container->set(
				self::SETTINGS_SCREEN,
				static fn ( Container $c ): SettingsScreen => new SettingsScreen(
					$c->get_typed( self::SETTINGS, Settings::class ),
					$c->get_typed( self::SCREEN, Screen::class ),
					$c->get_typed( self::AUDIT, AuditLog::class )
				)
			);

			// Declared first, so that the register is the first tab: it is what
			// somebody opening "WooCommerce → DDT" came to see.
			$container->set(
				self::REGISTER_SCREEN,
				static fn ( Container $c ): RegisterScreen => new RegisterScreen(
					$c->get_typed( self::DOCUMENTS, DocumentRepositoryInterface::class ),
					$c->get_typed( self::SCREEN, Screen::class )
				)
			);

			$container->set(
				self::NUMBERING_SCREEN,
				static fn ( Container $c ): NumberingScreen => new NumberingScreen(
					$c->get_typed( self::SETTINGS, Settings::class ),
					$c->get_typed( self::SEQUENCES, SequenceRepositoryInterface::class ),
					$c->get_typed( self::SCREEN, Screen::class ),
					$c->get_typed( self::CLOCK, ClockInterface::class ),
					$c->get_typed( self::AUDIT, AuditLog::class )
				)
			);

			$container->set(
				self::EDIT_SCREEN,
				static fn ( Container $c ): EditScreen => new EditScreen(
					$c->get_typed( self::DOCUMENTS, DocumentRepositoryInterface::class ),
					$c->get_typed( self::DOCUMENT_FACTORY, DocumentFactory::class ),
					$c->get_typed( self::FULFILMENT, OrderFulfilment::class ),
					$c->get_typed( self::AUDIT, AuditLog::class ),
					$c->get_typed( self::ISSUER, Issuer::class ),
					$c->get_typed( self::MAILER, DocumentMailer::class )
				)
			);

			$container->set(
				self::ORDER_METABOX,
				static fn ( Container $c ): OrderMetabox => new OrderMetabox(
					$c->get_typed( self::FULFILMENT, OrderFulfilment::class )
				)
			);

			$container->set(
				self::MENU,
				static fn ( Container $c ): Menu => new Menu(
					$c->get_typed( self::SCREEN, Screen::class ),
					$c->get_typed( self::EDIT_SCREEN, EditScreen::class )
				)
			);
		}

		return $container;
	}
}
