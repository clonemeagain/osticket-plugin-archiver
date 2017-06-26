<?php
require_once (INCLUDE_DIR . 'class.signal.php');
require_once ('config.php');

/**
 * The goal of this Plugin is to archive old plugins
 */
class ArchiverPlugin extends Plugin {
	var $config_class = 'ArchiverPluginConfig';
	private $path, $mode, $auto, $max_age, $freq, $num;
	public function bootstrap() {
		$c = $this->getConfig ();
		
		$this->path = $c->get ( 'archive-path' );
		
		if (! $this->path) {
			// This hasn't been set, no point complaining, they'll figure it out.. right?
			$this->log ( "Archive path not set, ignoring" );
			return;
		}
		
		if (! is_dir ( $this->path ) && ! is_writable ( $this->path )) {
			$this->log ( "Check the permissions of the path {$this->path}, can't write to it." );
			return;
		}
		
		$class_ticket_php = dirname ( dirname ( dirname ( __FILE__ ) ) ) . 'class.ticket.php';
		if (! preg_match ( '/ticket\.before\.delete/', file_get_contents ( $class_ticket_php ) )) {
			// We are unable to detect tickets being deleted! shit!
			$this->log ( "Please add Signal to class.tickets.php " );
			
			// Don't do anything.. just bail man!
			return;
		}
		
		// Register handler to detect tickets being deleted.. really depends on user adding the line to the class.tickets.php file
		// kinda breaks all archiving without that.. wonder if we can check for it?
		
		Signal::connect ( 'ticket.before.delete', function ($ticket) {
			$this->archivTicket ( $ticket );
		} );
		
		$this->mode = ( bool ) ($c->get ( 'mode' ) == 'basic');
		$this->auto = ( bool ) $c->get ( 'purge' );
		
		if ($this->auto) {
			// Runs on every model.created, so, we need to ensure we don't run too often.
			Signal::connect ( 'model.created', function ($obj, &$vars) {
				// We'll ignore $obj & $vars, we're not interested,
				// we just wanted something that happened fairly regularly to trigger this
				$this->max_age = ( int ) $c->get ( 'purge-age' );
				if ($this->max_age > 999) {
					$this->max_age = 999; // I fuckin hope this is still working in 80+ years and someone hits this.
				}
				$this->freq = ( int ) $c->get ( 'purge-frequency' );
				$this->num = ( int ) $c->get ( 'purge-num' );
				
				// Find purge frequency in a comparable format, seconds:
				$freq_in_seconds = $this->freq * 60 * 60;
				
				// If we've not got a lock/touch-file, let's make one, and if so
				// compare it's timestamp to the frequency the admin want's us to use
				// If now's timestamp minus the file's timestamp is greater than that frequency in seconds
				// then it's time to party!
				if (! file_exists ( 'touch' ) || (time () - stat ( 'touch' ) ['mtime'] > $freq_in_seconds)) {
					touch ( 'touch' ); // we'll touch this file, and get on with our work.
					                   // Check for purgeable files & delete them.
					if ($purgeme = $this->findOldTickets ()) {
						$done = 0;
						foreach ( $purgeme as $ticket_id ) {
							$ticket = Ticket::lookup ( $ticket_id );
							$ticket->delete (); // Triggers the Archive code
							$this->archiveTicket ( $ticket );
							if ($done ++ > $this->num) {
								break;
							}
						}
					}
				}
			} );
		}
	}
	
	/**
	 * Retrieves an array of ticket_id's from the database
	 *
	 * Filtered to only show those that were closed longer than max-age months ago.
	 *
	 * @return NULL|unknown|array|string|QuerySet
	 */
	private function findOldTickets() {
		return db_fetch_array ( db_query ( 'SELECT ticket_id FROM ' . TICKET_TABLE . ' WHERE closed > DATE_SUB(NOW(), INTERVAL ' . $this->max_age . ' MONTH)' ) );
		
		// Attempt to use ORM?
		$query = Ticket::objects ()->filter ( array (
				'closed' => '>= DATESUB(NOW(), INTERVAL ' . $this->max_age . ' MONTH)' 
		) );
		
		return $query->values_flat ( 'ticket_id' );
	}
	private function archiveTicket(Ticket $ticket) {
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
		
		require_once (INCLUDE_DIR . 'class.pdf.php');
		if (! is_string ( $psize )) {
			if ($_SESSION ['PAPER_SIZE'])
				$psize = $_SESSION ['PAPER_SIZE'];
			elseif (! $thisstaff || ! ($psize = $thisstaff->getDefaultPaperSize ()))
				$psize = 'Letter';
		}
		
		$pdf = new Ticket2PDF ( $ticket, $psize, TRUE );
		
		$number = $ticket->getNumber ();
		$subject = $ticket->getSubject ();
		
		if ($this->mode) {
			$name = 'Ticket-' . $ticket->getSubject () . '_' . $ticket->getNumber () . '.pdf';
			$pdf->Output ( "{$this->path}/$name", 'F' );
			// TODO: Set timestamp of file to when closed
		} else {
			// TODO: Advanced Mode Archive!
			// Let's get retarded!
			// /path/to/archives/{$department}/{$user}/{$ticket_subject}_{$ticket_id}/
			// Make a directory for the ticket
			$dept = $ticket->getDeptName ();
			$user = $ticket->getOwner ()->getName ();
			
			$folder = $this->path . '/' . $dept . '/' . $user . '/' . $ticket->getSubject () . '_' . $ticket->getNumber ();
			
			if (! is_dir ( $folder )) {
				mkdir ( $folder );
			}
			// Fetch all metadata including thread/mailheaders/attachments etc
			$export = array (
					'ticket' => $ticket,
					'recipients' => $ticket->getThread ()->getAllRecipients (),
					'mail' => $ticket->getThread ()->findOriginalEmailMessage (),
					'thread' => $ticket->getThread () 
			);
			file_put_contents ( "$folder/meta.json", json_encode ( $export ) );
			
			// start dumping attachments:
			foreach ( $ticket->getThread ()->getEntries ()->getAttachments () as $a ) {
				// ?? Does that even work
				$file = $a->file;
				$filename = $file->getFilename ();
				$this->copyFile ( $file, "$folder/attachment_$filename" );
			}
			
			// Save the thread as normal PDF
			$pdf->Output ( "$folder/Ticket-{$ticket->getNumber()}.pdf", 'F' );
			// TODO: Set timestamp of file to when closed
		}
		
		// Remember what the user selected - for autoselect on the next print.
		$_SESSION ['PAPER_SIZE'] = $psize;
	}
	
	/**
	 * Attempt to capture the file..
	 * into a file. :-|
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
		unlink ( 'touch' ); // purge our temp lockfile.
		
		parent::uninstall ( $errors );
	}
	
	/**
	 * Plugin seems to want this.
	 */
	public function getForm() {
		return array ();
	}
}