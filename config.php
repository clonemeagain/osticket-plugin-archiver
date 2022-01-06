<?php
require_once INCLUDE_DIR . 'class.plugin.php';
class ArchiverPluginConfig extends PluginConfig {
	// Provide compatibility function for versions of osTicket prior to
	// translation support (v1.9.4)
	function translate() {
		if (! method_exists ( 'Plugin', 'translate' )) {
			return array (
					function ($x) {
						return $x;
					},
					function ($x, $y, $n) {
						return $n != 1 ? $y : $x;
					} 
			);
		}
		return Plugin::translate ( 'archiver' );
	}
	function pre_save($config, &$errors) {
		
		// Delete mode doesn't actually need any of this:
		if($config['mode'] == 'delete')
			return TRUE;
		
		$server_user = posix_getpwuid ( posix_geteuid () ) ['name']; // https://stackoverflow.com/a/17709403
		                                                             
		// Ensure the webserver can write to the archive folder:
		if (! is_dir ( $config ['archive-path'] ) && ! mkdir ( $config ['archive-path'] ) || (! is_writable ( $config ['archive-path'] ))) {
			$errors ['err'] .= "\nCheck the permissions of the path {$config['archive-path']}, $server_user can't write to it.";
		}
		/**
		 * We Need to ensure our signal will be sent.
		 * Wonder if there is a way of checking updates? What if we stored a timestamp of the file and checked it..
		 * fark.
		 */
		// Attempt to parse /include/class.ticket.php
		$class_ticket_php = dirname ( dirname ( dirname ( __FILE__ ) ) ) . '/class.ticket.php';
		if (! file_exists ( $class_ticket_php )) {
			$errors ['err'] .= "\nUnable to open class.ticket.php to check for signal.";
		}
		
		// Attempt to find our signal string:
		if (preg_match ( '/ticket\.before\.delete/', file_get_contents ( $class_ticket_php ) ) == FALSE) {
			// We are unable to detect tickets being deleted! shit!
			// $errors ['err'] .= "\nThe signal is not being sent from class.tickets.php, check README."; //uncomment this to verify the MOD has been properly put in place (see Readme.md)
		}
		if (isset ( $errors ['err'] ))
			return false;
		
		// Peachy? Yeah
		return true;
	}
	
	/**
	 * Build an Admin settings page.
	 *
	 * {@inheritdoc}
	 *
	 * @see PluginConfig::getOptions()
	 */
	function getOptions() {
		list ( $__, $_N ) = self::translate ();
		return array (
				'archive-break' => new SectionBreakField ( array (
						'label' => $__ ( 'Archive Configuration' ) 
				) ),
				'mode' => new ChoiceField ( array (
						'label' => $__ ( 'Archive type' ),
						'choices' => array (
								'delete' => $__ ( 'Just delete tickets (no save)' ),
								'basic' => $__ ( 'Basic Archive Mode (save a PDF only).' ),
								'advanced' => $__ ( 'Advanced Archive Mode (stores all attachments and metadata in a folder per ticket.' ) 
						),
						'default' => 'basic',
						'hint' => $__ ( 'Either PDF only or Everything' ) 
				) ),
				'archive-path' => new TextboxField ( array (
						'label' => $__ ( 'Archive location' ),
						'hint' => $__ ( 'Something that is not publically accessible would be good, NOT THE SAME AS ATTACHMENTS PATH!.' ),
						'size' => 80,
						'length' => 256,
						'placeholder' => '/opt/osTicket/archive',
				) ),
				'include-notes' => new Booleanfield ( array (
						'label' => $__ ( 'Include private notes in archived tickets' ) 
				) ),
				'purge-break' => new SectionBreakField ( array (
						'label' => $__ ( 'Auto Purge tickets via cron' ),
						'hint' => $__ ( 'Or you know, you could manually delete old tickets..' ) 
				) ),
				'purge' => new BooleanField ( array (
						'label' => $__ ( 'Auto-purge old closed tickets' ),
						'hint' => $__ ( '' ) 
				) ),
				'purge-age' => new TextboxField ( array (
						'default' => '999',
						'label' => $__ ( 'Max age (in months) for tickets' ),
						'hint' => $__ ( 'Keep everything younger than this. 999 months = 83 years' ),
						'size' => 5,
						'length' => 3  // 83.25 years
				) ),
				'purge-frequency' => new ChoiceField ( array (
						'label' => $__ ( 'Purge Frequency' ),
						'choices' => array (
								'1' => $__ ( 'Every Hour' ),
								'2' => $__ ( '2 Hours' ),
								'6' => $__ ( '6 Hours' ),
								'12' => $__ ( '12 Hours' ),
								'24' => $__ ( '1 Day' ),
								'36' => $__ ( '36 Hours' ),
								'48' => $__ ( '2 Days' ),
								'72' => $__ ( '72 Hours' ),
								'168' => $__ ( '1 Week' ) 
						),
						'default' => '2',
						'hint' => $__ ( "How often should we archive & purge old tickets?" ) 
				) ),
				'purge-num' => new TextboxField ( array (
						'label' => $__ ( 'Purge Number' ),
						'hint' => $__ ( "How many tickets should we purge each time?" ),
						'default' => 10 
				) ) 
		);
	}
}
