<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * PostPress AI — Videos Admin Page
 * Clean, stable, zero conditional logic.
 */

if ( ! function_exists( 'ppa_render_videos_page' ) ) {

    function ppa_render_videos_page() {
        ?>

        <div class="wrap ppa-admin ppa-videos">
            <h1>PostPress AI — Videos</h1>

            <div class="ppa-card ppa-video-featured">
                <div class="ppa-video-embed">
                    <iframe width="100%" height="420"
                        src="https://www.youtube.com/embed/dQw4w9WgXcQ"
                        title="Featured Video"
                        frameborder="0"
                        allowfullscreen>
                    </iframe>
                </div>

                <div class="ppa-video-meta">
                    <h2 class="title">Getting Started with PostPress AI</h2>
                    <p class="ppa-help">
                        Learn the complete drafting workflow: idea → preview → saved draft inside WordPress.
                    </p>
                </div>
            </div>

            <div class="ppa-video-grid">

                <?php for ( $i = 1; $i <= 6; $i++ ) : ?>

                    <div class="ppa-card ppa-video-card">
                        <div class="ppa-video-thumb">
                            <iframe width="100%" height="200"
                                src="https://www.youtube.com/embed/dQw4w9WgXcQ"
                                title="Video <?php echo esc_attr( $i ); ?>"
                                frameborder="0"
                                allowfullscreen>
                            </iframe>
                        </div>

                        <h3 class="title">Workflow Tip <?php echo esc_html( $i ); ?></h3>

                        <p class="ppa-help">
                            Quick focused tip to improve your drafting speed and clarity.
                        </p>

                        <a href="https://youtube.com" target="_blank" class="button button-secondary">
                            Watch on YouTube
                        </a>
                    </div>

                <?php endfor; ?>

            </div>
        </div>

        <?php
    }
}