<?php

class BetterEmailPlugin extends MantisPlugin {

	function register() {
		$this->name        = 'BetterEmail';
		$this->description = 'Clean HTML notification emails';
		$this->version     = '1.4';
		$this->requires    = [ 'MantisCore' => '2.0.0' ];
		$this->author      = 'Chris Carey';
	}

	function hooks() {
		return [
			'EVENT_EMAIL_CREATE_SEND_PROVIDER' => 'create_sender',
		];
	}

	function create_sender( $p_event ) {
		require_once( __DIR__ . '/BetterEmailSender.class.php' );
		return new BetterEmailSender();
	}

	// ============================================================
	// Main HTML builder – called by BetterEmailSender
	// ============================================================

	public function build_html( string $plain_text, ?int $bug_id ) : string {

		$action_title = $this->parse_action_title( $plain_text );
		$notes        = $this->parse_bugnotes( $plain_text );
		$history      = $this->parse_history( $plain_text );

		// ---- Bug metadata ----
		$bug_html      = '';
		$url           = '#';
		$bug_id_padded = '';
		$project       = '';

		if ( $bug_id ) {
			try {
				$bug           = bug_get( $bug_id );
				$url           = string_get_bug_view_url_with_fqdn( $bug_id );
				$bug_id_padded = bug_format_id( $bug_id );
				$project       = htmlspecialchars( project_get_field( $bug->project_id, 'name' ) );
				$summary       = htmlspecialchars( $bug->summary );

				$status_label   = get_enum_element( 'status',   $bug->status );
				$priority_label = get_enum_element( 'priority', $bug->priority );
				$severity_label = get_enum_element( 'severity', $bug->severity );

				$status_color   = $this->status_color( $bug->status );
				$priority_color = $this->priority_color( $bug->priority );

				$reporter = htmlspecialchars( user_get_name( $bug->reporter_id ) );
				$handler  = $bug->handler_id ? htmlspecialchars( user_get_name( $bug->handler_id ) ) : '<em style="color:#aaa">Unassigned</em>';
				$category = htmlspecialchars( category_full_name( $bug->category_id, false ) );

				$description = $this->format_body_text( $bug->description );
				$steps       = $bug->steps_to_reproduce ? $this->format_body_text( $bug->steps_to_reproduce ) : '';
				$additional  = $bug->additional_information ? $this->format_body_text( $bug->additional_information ) : '';
				$created_str = date( config_get( 'normal_date_format' ), $bug->date_submitted );
				$updated_str = date( config_get( 'normal_date_format' ), $bug->last_updated );

				// Only show files explicitly attached in THIS update.
				// If the plain text has no "Attached Files:" block, omit the section.
				require_api( 'file_api.php' );
				$noted_filenames = $this->parse_attached_filenames( $plain_text );
				if ( !empty( $noted_filenames ) ) {
					$all_attachments = file_get_visible_attachments( $bug_id );
					$attachments     = array_values( array_filter(
						$all_attachments,
						function( $a ) use ( $noted_filenames ) {
							return in_array( $a['display_name'], $noted_filenames, true );
						}
					) );
				} else {
					$attachments = [];
				}

				$bug_html = $this->render_bug_card(
					$bug_id_padded, $url, $summary, $project,
					$status_label, $status_color,
					$priority_label, $priority_color,
					$severity_label,
					$reporter, $handler, $category,
					$created_str, $updated_str,
					$description, $steps, $additional,
					$action_title, $notes, $history,
					$attachments
				);
			} catch ( Exception $e ) {
				// fallback below
			}
		}

		if ( empty( $bug_html ) ) {
			$bug_html = $this->render_fallback( $plain_text );
		}

		return $this->render_email_shell( $project ?: 'MantisBT', $url, $bug_html );
	}

	// ============================================================
	// Rendering helpers
	// ============================================================

	private function render_email_shell( string $project, string $url, string $content ) : string {
		$year = date( 'Y' );
		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<style>
  body { margin:0; padding:0; background:#F4F5F7; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,sans-serif; }
  .wrapper { max-width:680px; margin:0 auto; padding:24px 16px; }
  .card { background:#fff; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,.12); overflow:hidden; }
  .meta-table td { padding:5px 12px 5px 0; font-size:13px; vertical-align:top; line-height:1.4; }
  .meta-table td:first-child { color:#6B778C; white-space:nowrap; padding-right:16px; font-weight:500; }
  .section { padding:20px 24px; border-top:1px solid #EBECF0; }
  .section-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6B778C; margin:0 0 8px; }
  .body-text { font-size:14px; line-height:1.6; color:#172B4D; white-space:pre-wrap; word-break:break-word; }
  .attach-list { list-style:none; margin:0; padding:0; }
  .attach-list li { display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px solid #F4F5F7; font-size:13px; }
  .attach-list li:last-child { border-bottom:none; }
  .attach-list a { color:#0052CC; text-decoration:none; font-weight:500; }
  .attach-list a:hover { text-decoration:underline; }
  .attach-list .size { color:#97A0AF; font-size:11px; margin-left:4px; }
  .note-block { background:#F4F5F7; border-radius:4px; padding:14px 16px; margin-bottom:10px; }
  .note-meta { font-size:12px; color:#6B778C; margin-bottom:6px; }
  .note-body { font-size:14px; color:#172B4D; line-height:1.55; white-space:pre-wrap; word-break:break-word; }
  .note-block.private { border-left:3px solid #FF8B00; }
  .history-row td { padding:4px 10px 4px 0; font-size:12px; color:#344563; vertical-align:top; border-bottom:1px solid #F4F5F7; }
  .history-row td:first-child { color:#6B778C; white-space:nowrap; }
  .btn { display:inline-block; background:#0052CC; color:#fff!important; text-decoration:none; padding:11px 20px; border-radius:4px; font-size:14px; font-weight:600; letter-spacing:.01em; }
  .reply-marker { font-size:12px; color:#97A0AF; border-top:1px dashed #DFE1E6; padding-top:14px; margin-top:6px; text-align:center; }
  @media(max-width:600px){
    .wrapper{padding:12px 8px}
    .section{padding:16px}
    .meta-two-col td{display:block;width:100%!important}
  }
</style>
</head>
<body>
<div class="wrapper">

  <!-- Header -->
  <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:12px">
    <tr>
      <td style="font-size:13px;font-weight:700;color:#0052CC;letter-spacing:.02em">
        &#x1F41E; MantisBT &middot; {$project}
      </td>
      <td align="right" style="font-size:12px;color:#6B778C">
        <a href="{$url}" style="color:#0052CC;text-decoration:none">View in browser &rarr;</a>
      </td>
    </tr>
  </table>

  <!-- Main card -->
  <div class="card">
    {$content}
  </div>

  <!-- Footer -->
  <p style="text-align:center;font-size:11px;color:#97A0AF;margin-top:20px;line-height:1.6">
    You are receiving this because you are watching or involved in this issue.<br>
    &copy; {$year} MantisBT
  </p>

</div>
</body>
</html>
HTML;
	}

	private function render_bug_card(
		string $bug_id_padded, string $url, string $summary, string $project,
		string $status_label, string $status_color,
		string $priority_label, string $priority_color,
		string $severity_label,
		string $reporter, string $handler, string $category,
		string $created_str, string $updated_str,
		string $description, string $steps, string $additional,
		string $action_title, array $notes, array $history,
		array $attachments = []
	) : string {

		$status_badge   = $this->status_badge( $status_label, $status_color );
		$priority_dot   = $this->priority_dot( $priority_label, $priority_color );

		// ---- Action banner ----
		$action_html = '';
		if ( !empty( $action_title ) ) {
			$action_esc  = htmlspecialchars( $action_title );
			$action_html = <<<HTML
<div style="background:#E3FCEF;border-left:4px solid #00875A;padding:12px 20px;font-size:13px;color:#006644;font-weight:500">
  {$action_esc}
</div>
HTML;
		}

		// ---- Metadata ----
		$meta = <<<HTML
<div class="section" style="padding-top:20px;padding-bottom:20px">
  <table class="meta-table meta-two-col" width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td width="50%">
        <table cellspacing="0" cellpadding="0" class="meta-table">
          <tr><td>Reporter</td><td>{$reporter}</td></tr>
          <tr><td>Assignee</td><td>{$handler}</td></tr>
          <tr><td>Project</td><td>{$project}</td></tr>
          <tr><td>Category</td><td>{$category}</td></tr>
        </table>
      </td>
      <td width="50%">
        <table cellspacing="0" cellpadding="0" class="meta-table">
          <tr><td>Status</td><td>{$status_badge}</td></tr>
          <tr><td>Priority</td><td>{$priority_dot} {$priority_label}</td></tr>
          <tr><td>Severity</td><td>{$severity_label}</td></tr>
          <tr><td>Updated</td><td>{$updated_str}</td></tr>
        </table>
      </td>
    </tr>
  </table>
</div>
HTML;

		// ---- Latest comment / note (show first, like JIRA) ----
		$notes_html = '';
		if ( !empty( $notes ) ) {
			$latest = $notes[ count( $notes ) - 1 ];
			$notes_html  = '<div class="section">';
			$notes_html .= '<p class="section-label">Latest Comment</p>';
			foreach ( $notes as $n ) {
				$private_class = $n['private'] ? ' private' : '';
				$private_label = $n['private'] ? ' &nbsp;<span style="font-size:11px;background:#FF8B00;color:white;border-radius:3px;padding:1px 5px">Private</span>' : '';
				$author_esc    = htmlspecialchars( $n['author'] );
				$date_esc      = htmlspecialchars( $n['date'] );
				$note_body     = $this->format_body_text( $n['note'] );
				$notes_html   .= <<<HTML
<div class="note-block{$private_class}">
  <div class="note-meta"><strong>{$author_esc}</strong> &nbsp;&middot;&nbsp; {$date_esc}{$private_label}</div>
  <div class="note-body">{$note_body}</div>
</div>
HTML;
			}
			$notes_html .= '</div>';
		}

		// ---- Description ----
		$desc_html = '';
		if ( !empty( $description ) ) {
			$desc_html = <<<HTML
<div class="section">
  <p class="section-label">Description</p>
  <div class="body-text">{$description}</div>
</div>
HTML;
		}

		// ---- Steps to reproduce ----
		$steps_html = '';
		if ( !empty( $steps ) ) {
			$steps_html = <<<HTML
<div class="section">
  <p class="section-label">Steps to Reproduce</p>
  <div class="body-text">{$steps}</div>
</div>
HTML;
		}

		// ---- Additional info ----
		$additional_html = '';
		if ( !empty( $additional ) ) {
			$additional_html = <<<HTML
<div class="section">
  <p class="section-label">Additional Information</p>
  <div class="body-text">{$additional}</div>
</div>
HTML;
		}

		// ---- History ----
		$history_html = '';
		if ( !empty( $history ) ) {
			$rows = '';
			foreach ( $history as $h ) {
				$date_esc  = htmlspecialchars( $h['date'] );
				$user_esc  = htmlspecialchars( $h['user'] );
				$field_esc = htmlspecialchars( $h['field'] );
				$change    = htmlspecialchars( $h['change'] );
				$rows .= <<<HTML
<tr class="history-row">
  <td>{$date_esc}</td>
  <td>{$user_esc}</td>
  <td>{$field_esc}</td>
  <td style="color:#172B4D">{$change}</td>
</tr>
HTML;
			}
			$history_html = <<<HTML
<div class="section">
  <p class="section-label">Changes</p>
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td style="font-size:11px;color:#6B778C;padding:0 10px 6px 0;font-weight:600">Date</td>
      <td style="font-size:11px;color:#6B778C;padding:0 10px 6px 0;font-weight:600">User</td>
      <td style="font-size:11px;color:#6B778C;padding:0 10px 6px 0;font-weight:600">Field</td>
      <td style="font-size:11px;color:#6B778C;padding:0 0 6px 0;font-weight:600">Change</td>
    </tr>
    {$rows}
  </table>
</div>
HTML;
		}

		// ---- Attachments ----
		$attachments_html = $this->render_attachments( $attachments, $url );

		// ---- CTA ----
		$cta = <<<HTML
<div class="section" style="text-align:center;padding-top:24px;padding-bottom:24px">
  <a href="{$url}" class="btn">View Issue #{$bug_id_padded}</a>
</div>
HTML;

		// ---- Reply separator (EmailReporting-compatible) ----
		$reply_sep = <<<HTML
<div class="section reply-marker">
  &#8593; Reply above this line to add a comment to issue #{$bug_id_padded} &#8593;
</div>
HTML;

		return <<<HTML
<!-- Issue title -->
<div style="padding:20px 24px 0">
  <div style="font-size:12px;color:#6B778C;margin-bottom:4px;font-weight:500">
    Issue #{$bug_id_padded}
  </div>
  <h1 style="margin:0 0 12px;font-size:20px;font-weight:700;color:#172B4D;line-height:1.3">
    {$summary}
  </h1>
</div>

{$action_html}
{$meta}
{$notes_html}
{$desc_html}
{$steps_html}
{$additional_html}
{$history_html}
{$attachments_html}
{$cta}
{$reply_sep}
HTML;
	}

	private function render_attachments( array $attachments, string $bug_url ) : string {
		if ( empty( $attachments ) ) {
			return '';
		}

		$base       = rtrim( config_get( 'path' ), '/' ) . '/';
		$items_html = '';
		$grid_html  = '';

		foreach ( $attachments as $a ) {
			$name   = htmlspecialchars( $a['display_name'] );
			$size   = $this->format_filesize( $a['size'] );
			$icon   = $this->file_icon( $a['file_type'] ?? '', $a['display_name'] );
			$dl_url = isset( $a['download_url'] )
			          ? htmlspecialchars( $base . $a['download_url'] )
			          : htmlspecialchars( $bug_url );

			// Images → floated thumbnail blocks
			// Each image lives in its own table with align="left" (Outlook reads this
			// as a float) plus style="float:left" for everything else.
			// They naturally wrap onto the next line when there's no more room —
			// no fixed row structure needed.
			if ( ( $a['type'] ?? '' ) === 'image' && isset( $a['download_url'] ) ) {
				$data_uri = $this->make_thumbnail_data_uri( $a['id'], 200 );
				if ( $data_uri ) {
					$grid_html .= <<<HTML
<table align="left" cellpadding="0" cellspacing="0"
       style="float:left;margin:0 8px 8px 0;border-collapse:collapse">
  <tr>
    <td style="vertical-align:top">
      <a href="{$dl_url}" style="display:block;line-height:0">
        <img src="{$data_uri}" alt="{$name}" width="200" height="200"
             style="display:block;width:200px;height:200px;
                    border-radius:4px;border:1px solid #EBECF0">
      </a>
      <div style="font-size:11px;color:#6B778C;margin-top:4px;
                  width:200px;overflow:hidden;text-overflow:ellipsis;
                  white-space:nowrap">{$name}</div>
    </td>
  </tr>
</table>
HTML;
					continue;
				}
			}

			// Non-image files → list
			$items_html .= <<<HTML
<li>
  <span style="font-size:16px;line-height:1">{$icon}</span>
  <a href="{$dl_url}">{$name}</a>
  <span class="size">{$size}</span>
</li>
HTML;
		}

		$total = count( $attachments );
		$label = $total === 1 ? '1 Attachment' : "{$total} Attachments";

		if ( $grid_html ) {
			// Clearing div ends the float context so the file list below sits correctly
			$grid_html = "<div style=\"margin-top:12px\">{$grid_html}<div style=\"clear:both;font-size:0;line-height:0\">&nbsp;</div></div>";
		}

		$list_html = $items_html ? "<ul class=\"attach-list\" style=\"margin-top:10px\">{$items_html}</ul>" : '';

		return <<<HTML
<div class="section">
  <p class="section-label">{$label}</p>
  {$grid_html}
  {$list_html}
</div>
HTML;
	}

	/**
	 * Load an image attachment via MantisBT's file API, resize to at most
	 * $max_px pixels on the longest side using GD, and return a JPEG data URI
	 * suitable for embedding directly in an <img src="..."> tag.
	 *
	 * Embedding as a data URI means:
	 *  - Works in all clients without authentication
	 *  - Outlook renders it at exactly the pixel dimensions we specify
	 *  - No massive full-resolution bitmap in the email
	 *
	 * Returns null on any failure (GD missing, unsupported format, etc.).
	 */
	private function make_thumbnail_data_uri( int $file_id, int $size = 160 ) : ?string {
		if ( !function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}

		try {
			$result = file_get_content( $file_id, 'bug' );
		} catch ( Exception $e ) {
			return null;
		}

		if ( !$result || empty( $result['content'] ) ) {
			return null;
		}

		$src = @imagecreatefromstring( $result['content'] );
		if ( $src === false ) {
			return null;
		}

		$orig_w = imagesx( $src );
		$orig_h = imagesy( $src );

		// Center square crop: take the largest square from the middle
		$crop   = min( $orig_w, $orig_h );
		$src_x  = (int) round( ( $orig_w - $crop ) / 2 );
		$src_y  = (int) round( ( $orig_h - $crop ) / 2 );

		$thumb = imagecreatetruecolor( $size, $size );

		// White background (JPEG doesn't support transparency)
		$white = imagecolorallocate( $thumb, 255, 255, 255 );
		imagefilledrectangle( $thumb, 0, 0, $size, $size, $white );

		// Resample: map the center square crop → $size × $size
		imagecopyresampled( $thumb, $src, 0, 0, $src_x, $src_y, $size, $size, $crop, $crop );
		imagedestroy( $src );

		ob_start();
		imagejpeg( $thumb, null, 82 );
		$jpeg = ob_get_clean();
		imagedestroy( $thumb );

		if ( empty( $jpeg ) ) {
			return null;
		}

		return 'data:image/jpeg;base64,' . base64_encode( $jpeg );
	}

	private function format_filesize( int $bytes ) : string {
		if ( $bytes >= 1048576 ) return round( $bytes / 1048576, 1 ) . ' MB';
		if ( $bytes >= 1024 )    return round( $bytes / 1024, 1 )    . ' KB';
		return $bytes . ' B';
	}

	private function file_icon( string $mime, string $name ) : string {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( strpos( $mime, 'image/' ) === 0 )                          return '&#x1F5BC;'; // 🖼
		if ( strpos( $mime, 'video/' ) === 0 )                          return '&#x1F3AC;'; // 🎬
		if ( strpos( $mime, 'audio/' ) === 0 )                          return '&#x1F3B5;'; // 🎵
		if ( strpos( $mime, 'text/' ) === 0 )                           return '&#x1F4C4;'; // 📄
		if ( in_array( $ext, [ 'pdf' ] ) )                              return '&#x1F4CA;'; // 📊
		if ( in_array( $ext, [ 'zip', 'gz', 'tar', 'rar', '7z' ] ) )   return '&#x1F4E6;'; // 📦
		if ( in_array( $ext, [ 'xls', 'xlsx', 'csv' ] ) )              return '&#x1F4C8;'; // 📈
		if ( in_array( $ext, [ 'doc', 'docx', 'odt', 'rtf' ] ) )       return '&#x1F4DD;'; // 📝
		return '&#x1F4CE;'; // 📎 generic
	}

	private function render_fallback( string $plain_text ) : string {
		$clean = preg_replace( '/={3,}/', str_repeat( '─', 40 ), $plain_text );
		$html  = nl2br( htmlspecialchars( $clean ) );
		return "<div style='padding:24px;font-size:14px;line-height:1.6;color:#172B4D'>$html</div>";
	}

	// ============================================================
	// Plain-text parsing
	// ============================================================

	/**
	 * Extract the first non-separator, non-empty line (the notification action title).
	 */
	public function parse_action_title( string $text ) : string {
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( $line );
			if ( $line !== '' && !preg_match( '/^[=\-]{3,}/', $line ) ) {
				return $line;
			}
		}
		return '';
	}

	/**
	 * Extract filenames from the "Attached Files:" block in the plain text.
	 * Returns an array of bare filename strings (empty if no block found).
	 */
	public function parse_attached_filenames( string $text ) : array {
		// Block looks like:
		// Attached Files:\n
		// - filename.png (7,360,557 bytes)\n
		// - another.pdf (12,000 bytes)\n
		if ( !preg_match( '/Attached Files:\s*\n((?:\s*-\s+.+\n?)+)/i', $text, $m ) ) {
			return [];
		}
		$names = [];
		foreach ( explode( "\n", trim( $m[1] ) ) as $line ) {
			// "- filename.ext (N bytes)" — strip leading "- " and trailing " (N bytes)"
			$line = trim( $line );
			if ( preg_match( '/^-\s+(.+?)\s*\(\d[\d,]*\s+bytes\)\s*$/i', $line, $fm ) ) {
				$names[] = trim( $fm[1] );
			}
		}
		return $names;
	}

	/**
	 * Extract bugnote blocks (between --- --- --- separators in the plain text).
	 * Returns array of ['author', 'date', 'note', 'private'].
	 */
	public function parse_bugnotes( string $text ) : array {
		$notes = [];
		// Separator line pattern: "--- --- --- ---..." or "--- ---"
		$sep   = '---[- ]+';
		$rx    = '/' . $sep . '\s*\n(.+?)\n' . $sep . '\s*\n(.*?)(?=' . $sep . '|\z)/s';

		if ( preg_match_all( $rx, $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$header = trim( $m[1] );
				$note   = trim( $m[2] );
				if ( empty( $note ) ) {
					continue;
				}
				$author  = $header;
				$date    = '';
				$private = (bool) preg_match( '/\(private\)/i', $header );
				// "(~00123) Username (role) - YYYY-MM-DD HH:MM"
				if ( preg_match( '/\(~\d+\)\s+(.+?)\s+\([^)]+\)\s+-\s+(\S+(?:\s+\S+)??)(?:\s+\(private\))?$/i', $header, $hm ) ) {
					$author = trim( $hm[1] );
					$date   = trim( $hm[2] );
				}
				$notes[] = [ 'author' => $author, 'date' => $date, 'note' => $note, 'private' => $private ];
			}
		}
		return $notes;
	}

	/**
	 * Extract change-history rows from the plain text footer.
	 * Returns array of ['date', 'user', 'field', 'change'].
	 */
	public function parse_history( string $text ) : array {
		$history = [];
		// History section starts with "Bug History" heading then a header row
		// then separator then data rows
		if ( !preg_match( '/Bug History\s*\n(.+?Date.+?)\n=+[^\n]*\n(.*?)(?:={3,}|\z)/s', $text, $m ) ) {
			return $history;
		}
		foreach ( explode( "\n", trim( $m[2] ) ) as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}
			// Columns are padded with fixed widths (17, 15, 25, 20)
			// Use a generous split on 2+ spaces to handle padding
			$cols = preg_split( '/\s{2,}/', $line, 4 );
			if ( count( $cols ) >= 3 ) {
				$history[] = [
					'date'   => trim( $cols[0] ?? '' ),
					'user'   => trim( $cols[1] ?? '' ),
					'field'  => trim( $cols[2] ?? '' ),
					'change' => trim( $cols[3] ?? '' ),
				];
			}
		}
		return $history;
	}

	// ============================================================
	// Colour helpers
	// ============================================================

	private function status_color( int $status ) : string {
		if ( $status >= 90 ) return '#253858'; // closed
		if ( $status >= 80 ) return '#00875A'; // resolved
		if ( $status >= 50 ) return '#0052CC'; // assigned / in-progress
		if ( $status >= 30 ) return '#5243AA'; // confirmed / acknowledged
		return '#6B778C';                       // new / feedback
	}

	private function priority_color( int $priority ) : string {
		if ( $priority >= 60 ) return '#BF2600'; // immediate
		if ( $priority >= 50 ) return '#DE350B'; // urgent
		if ( $priority >= 40 ) return '#FF8B00'; // high
		if ( $priority >= 30 ) return '#0052CC'; // normal
		if ( $priority >= 20 ) return '#00B8D9'; // low
		return '#97A0AF';                         // none
	}

	private function status_badge( string $label, string $color ) : string {
		$esc = htmlspecialchars( $label );
		return "<span style='display:inline-block;background:{$color};color:white;"
		     . "padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;"
		     . "letter-spacing:.04em;text-transform:uppercase'>{$esc}</span>";
	}

	private function priority_dot( string $label, string $color ) : string {
		return "<span style='display:inline-block;width:10px;height:10px;"
		     . "border-radius:50%;background:{$color};vertical-align:middle;"
		     . "margin-right:4px'></span>";
	}

	// ============================================================
	// Text formatting
	// ============================================================

	private function format_body_text( string $text ) : string {
		return nl2br( htmlspecialchars( trim( $text ) ) );
	}
}
