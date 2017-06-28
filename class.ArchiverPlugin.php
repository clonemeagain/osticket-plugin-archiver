<?php
require_once (INCLUDE_DIR . 'class.signal.php');
require_once ('config.php');

/**
 * The goal of this Plugin is to archive old Tickets
 *
 * It provides an Archive function, converting tickets to PDF (using normal PDF Export)
 * By intercepting the Delete function (via Signal), and archiving before delete finishes.
 *
 * It also provides a cron task to archive & delete old tickets that you don't need anymore.
 */
class ArchiverPlugin extends Plugin {
	const DEBUG = FALSE;
	/**
	 * I believe this is part of the Plugin spec, which config to use
	 *
	 * @var string
	 */
	var $config_class = 'ArchiverPluginConfig';
	
	/**
	 * Where are we storing our archvives?
	 *
	 * @var string $path to archived tickets
	 */
	private $path;
	
	/**
	 * Run on every instantiation of osTicket..
	 * needs to be concise
	 *
	 * {@inheritdoc}
	 *
	 * @see Plugin::bootstrap()
	 */
	public function bootstrap() {
		// Register handler to detect tickets being deleted..
		// really depends on admin adding the line to the class.tickets.php file
		// kinda breaks all archiving without that, well, unless Delete Only mode is specified.
		if ($this->getConfig ()->get ( 'mode' ) !== 'delete') {
			Signal::connect ( 'ticket.before.delete', function ($ticket) {
				if (self::DEBUG) {
					print "Ticket delete signal received, initiating Archive!\n";
				}
				$this->archive ( $ticket );
			} );
		}
		
		if ($this->getConfig ()->get ( 'purge' )) {
			// Register cron handler to archive & delete tickets on schedule
			Signal::connect ( 'cron', function ($ignore1, $ignore2) {
				// Do we care if it's autocron? (bool) $ignore2['autocron']
				$this->autoPurge ();
			} );
		}
	}
	
	/**
	 * Purges tickets on cron
	 */
	private function autoPurge() {
		$config = $this->getConfig ();
		
		// Instead of storing "next-run", we store "last-run", then compare frequency, in case frequency changes.
		$last_run = $config->get ( 'last-run' );
		$now = time (); // Assume server timezone doesn't change enough to break this
		                
		// Find purge frequency in a comparable format, seconds:
		$freq_in_seconds = ( int ) $config->get ( 'purge-frequency' ) * 60 * 60;
		
		// Calculate when we want to run next:
		$next_run = $last_run + $freq_in_seconds;
		
		// Compare intention with reality:
		if (self::DEBUG || ! $next_run || $now > $next_run) {
			// if (self::DEBUG)
			// print "Running purge\n";
			$config->set ( 'last-run', $now );
			
			// Fetch the rest of the admin settings, now that we're actually going through with this:
			$max_age = ( int ) $config->get ( 'purge-age' );
			// if ($max_age > 999) {
			// $max_age = 999; // I fuckin hope this is still working in 80+ years
			// }
			
			// Find deletable tickets in the database:
			foreach ( $this->findOldTickets ( $max_age, $config->get ( 'purge-num' ) ) as $ticket_id ) {
				// Trigger the Archive code via signal above by simply deleting the ticket
				$t = Ticket::lookup ( $ticket_id );
				if ($t instanceof Ticket) {
					$t->delete ();
				}
			}
		}
	}
	
	/**
	 * Retrieves an array of ticket_id's from the database
	 *
	 * Filtered to only show those that were closed longer than $age_months months ago, oldest first.
	 *
	 * @return NULL|unknown|array|string|QuerySet
	 * @param int $age_months        	
	 * @param int $max_purge        	
	 */
	private function findOldTickets($age_months, $max_purge = 10) {
		if (! $age_months) {
			return array ();
		}
		
		$ids = array ();
		$r = db_query ( 'SELECT ticket_id FROM ' . TICKET_TABLE . ' WHERE closed > DATE_SUB(NOW(), INTERVAL ' . $age_months . ' MONTH) ORDER BY ticket_id ASC LIMIT ' . $max_purge );
		while ( $i = db_fetch_array ( $r ) ) {
			$ids [] = $i ['ticket_id'];
		}
		if (self::DEBUG)
			error_log ( "Deleting " . count ( $ids ) );
		
		return $ids;
	}
	
	/**
	 * Saves details for a ticket to filesystem
	 *
	 * @param Ticket $ticket        	
	 */
	private function archive(Ticket $ticket) {
		/**
		 * Pretty much a duplicate of $ticket->pdfExport(),
		 * // Print ticket...
		 * export the ticket thread as PDF.
		 * function pdfExport($psize='Letter', $notes=false) {
		 * global $thisstaff;
		 *
		 * require_once(INCLUDE_DIR.'class.pdf.php');
		 * if (!is_string($psize)) {
		 * if ($_SESSION['PAPER_SIZE'])
		 * $psize = $_SESSION['PAPER_SIZE'];
		 * elseif (!$thisstaff || !($psize = $thisstaff->getDefaultPaperSize()))
		 * $psize = 'Letter';
		 * }
		 *
		 * $pdf = new Ticket2PDF($this, $psize, $notes);
		 * $name = 'Ticket-'.$this->getNumber().'.pdf';
		 * Http::download($name, 'application/pdf', $pdf->Output($name, 'S'));
		 * //Remember what the user selected - for autoselect on the next print.
		 * $_SESSION['PAPER_SIZE'] = $psize;
		 * exit;
		 * }
		 */
		global $thisstaff;
		
		if (! $thisstaff) {
			// fuck.. we need one for the pdf export to work.. let's find whoever closed the ticket, and make them the active user.
			$thisstaff = $ticket->getStaff ();
		}
		
		$ticket->getThreadEntries (); // Force loading thread.. ffs.
		
		$psize = false;
		
		if (! defined ( 'STAFFINC_DIR' )) {
			// Normally this constant is only defined when staff are logged in..
			// but we'll possibly be running from cron, so it won't be.
			define ( 'STAFFINC_DIR', INCLUDE_DIR . 'staff/' );
		}
		
		require_once (INCLUDE_DIR . 'class.pdf.php');
		if (! is_string ( $psize )) {
			if ($_SESSION ['PAPER_SIZE'])
				$psize = $_SESSION ['PAPER_SIZE'];
			elseif (! $thisstaff || ! ($psize = $thisstaff->getDefaultPaperSize ()))
				$psize = 'Letter';
		}
		$path = $this->getConfig ()->get ( 'archive-path' );
		
		$pdf = new Ticket2PDF ( $ticket, $psize, ($this->getConfig ()->get ( 'include-notes' )) );
		
		$number = $ticket->getNumber ();
		$subject = $ticket->getSubject ();
		
		// Figure out what type of archive the admin wanted.
		if ($this->getConfig ()->get ( 'mode' ) == 'basic') {
			// Ignore format warning about static usage, build a filename that won't break the filesystem:
			$name = @Format::slugify ( 'Ticket-' . $ticket->getSubject () . '_' . $ticket->getNumber () ) . '.pdf';
			
			// Specify that we want a file output, not an HTML Attachment/Download:
			$pdf->Output ( "{$path}/$name", 'F' );
			return;
			// TODO: Set timestamp of file to when ticket closed
		} else {
			// TODO: Advanced Mode Archive!
			// Let's get retarded!
			// /path/to/archives/{$department}/{$user}/{$ticket_subject}_{$ticket_id}/
			// Make a directory for the ticket
			$dept = $ticket->getDeptName ();
			$user = $ticket->getOwner ()->getName ();
			
			$folder = $path . @Format::slugify ( "/$dept/$user/{$ticket->getSubject ()}_{$ticket->getNumber ()}" );
			
			if (! is_dir ( $folder )) {
				mkdir ( $folder, 0755, TRUE );
			}
			// Fetch metadata for the ticket
			$export = array (
					'ticket' => $ticket,
					// 'recipients' => $ticket->getThread ()->getAllRecipients (),
					// 'mail' => $ticket->getThread ()->findOriginalEmailMessage (),
					'thread' => $ticket->getThread () 
			);
			file_put_contents ( "$folder/meta.json", json_encode ( $export ) );
			
			// start dumping attachments:
			foreach ( $ticket->getThread ()->getEntries ()->getAttachments () as $a ) {
				// ?? Does that even work completely untested so far.
				$file = $a->file; // Should be AttachmentFile objects
				$filename = $file->getFilename ();
				$this->copyFile ( $file, "$folder/attachment_$filename" );
			}
			
			ob_clean ();
			// Save the thread as normal PDF
			$pdf->Output ( "$folder/Ticket-{$ticket->getNumber()}.pdf", 'F' );
			// TODO: Set timestamp of file to when closed
		}
	}
	
	/**
	 * Attempt to capture the file..
	 * into a file. :-|
	 *
	 * Completely untested..
	 *
	 * @param AttachmentFile $file        	
	 * @param string $dest
	 *        	(path to save file into)
	 */
	private function copyFile(AttachmentFile $file, $dest) {
		$bk = $file->open ();
		try {
			ob_start ();
			$bk->passthru ();
			file_put_contents ( $dest, ob_get_clean () );
		} catch ( IOException $ex ) {
			$this->log ( "Errors were encountered saving attachment to $dest" );
		}
	}
	
	/**
	 * Private logging function,
	 *
	 * Logs to the Admin logs, and to the webserver logs.
	 *
	 * @param unknown $message        	
	 */
	private function log($message) {
		global $ost;
		
		$ost->logInfo ( "ArchiverPlugin", $message );
		error_log ( "osTicket ArchivePlugin: $message" );
	}
	
	/**
	 * Required stub.
	 *
	 * {@inheritdoc}
	 *
	 * @see Plugin::uninstall()
	 */
	function uninstall() {
		$errors = array ();
		
		// Do we send an email to the admin telling him about the space used by the archive?
		global $ost;
		$ost->alertAdmin ( 'Plugin: Archiver has been uninstalled', 'Please note, the archive directory has not been deleted, however the configuration for the plugin has been.', true );
		
		parent::uninstall ( $errors );
	}
	
	/**
	 * Plugin seems to want this.
	 */
	public function getForm() {
		return array ();
	}
}