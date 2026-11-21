<?php
/**
 * Home page. Generated from the original static design; product, price and cart come from WooCommerce.
 */
defined('ABSPATH') || exit;
get_header();
$nv_p = nv_featured_product();
?>
<!-- 2. Hero -->
<section class="hero" aria-labelledby="hero-title">
  <svg class="hero__leaf" aria-hidden="true"><use href="#i-neem-leaf"/></svg>
  <svg class="hero__leaf hero__leaf--2" aria-hidden="true"><use href="#i-neem-leaf"/></svg>
  <div class="container hero__inner">
    <div class="hero__copy">
      <p class="eyebrow eyebrow--light">Neem sticks · Datun</p>
      <h1 id="hero-title">Rediscover <em>Traditional</em> Oral Care</h1>
      <p class="hero__sub">The natural, plastic-free toothbrush for a healthier smile.</p>
      <div class="btn-row">
        <a class="btn btn-light" href="#shop-neem-sticks">Shop neem sticks</a>
        <a class="btn btn-outline-light" href="#why-neem">Why neem?</a>
      </div>
      <ul class="hero__points">
        <li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>100% neem twigs</li>
        <li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>Plastic-free packing</li>
        <li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>Ships across India</li>
      </ul>
    </div>
    <div class="hero__media">
      <img class="hero__img" src="<?php echo esc_url(nv_asset('img/natural/hero-neem-sticks-leaves.jpg')); ?>"
           srcset="<?php echo esc_url(nv_asset('img/natural/hero-neem-sticks-leaves-600.jpg')); ?> 600w, <?php echo esc_url(nv_asset('img/natural/hero-neem-sticks-leaves.jpg')); ?> 900w"
           sizes="(min-width: 900px) 540px, 100vw" width="900" height="900" fetchpriority="high" decoding="async"
           alt="A bundle of natural neem sticks (datun) with fresh green neem leaves">
      <p class="hero__badge"><svg class="icon" aria-hidden="true"><use href="#i-sprout"/></svg>Biodegradable</p>
    </div>
  </div>
</section>

<!-- 3. Trust badges -->
<section class="trust" aria-label="Our promises">
  <ul class="container trust__list">
    <li class="trust__item"><span class="trust__icon"><svg class="icon" aria-hidden="true"><use href="#i-flask-off"/></svg></span>Chemical-free</li>
    <li class="trust__item"><span class="trust__icon"><svg class="icon" aria-hidden="true"><use href="#i-plastic-off"/></svg></span>Plastic-free</li>
    <li class="trust__item"><span class="trust__icon"><svg class="icon" aria-hidden="true"><use href="#i-mortar"/></svg></span>Ayurvedic care</li>
    <li class="trust__item"><span class="trust__icon"><svg class="icon" aria-hidden="true"><use href="#i-wind"/></svg></span>Naturally fresh</li>
  </ul>
</section>

<!-- 4. Featured product -->
<section class="section" id="shop-neem-sticks" aria-labelledby="featured-title">
  <div class="container feature" >
    <div class="feature__media feature__media--photo reveal">
      <img src="<?php echo esc_url(nv_asset('img/natural/feature-bundle-leaf.jpg')); ?>" width="960" height="1200" loading="lazy" decoding="async"
           alt="A bundle of hand-cut neem sticks tied with twine, beside a sprig of neem leaves">
      <span class="tag"><?php echo esc_html($nv_p ? ($nv_p->get_meta('nv_badge') ?: 'Bestseller') : ''); ?></span>
    </div>
    <div class="feature__body reveal" style="--delay:.1s">
      <p class="eyebrow">The neemveda essential</p>
      <h2 id="featured-title" class="feature__title"><?php echo esc_html($nv_p ? $nv_p->get_name() : 'neemveda Natural Neem Sticks'); ?></h2>
      <p class="feature__pack">Pack of <?php echo esc_html($nv_p ? ($nv_p->get_meta('nv_count') ?: '30') : '30'); ?> Neem Sticks</p>
      <p class="price price--lg"><?php echo $nv_p ? wp_kses_post($nv_p->get_price_html()) : ''; ?></p>
      <p class="price-note">Inclusive of all taxes. Free shipping on orders of <?php echo esc_html(nv_free_shipping_text()); ?> and above.</p>
      <p><?php echo esc_html($nv_p ? wp_strip_all_tags($nv_p->get_short_description()) : ''); ?> Just chew, brush and rinse, the way India has for generations.</p>

      <?php if ($nv_p && $nv_p->is_purchasable() && $nv_p->is_in_stock()) : ?>
      <form class="buy-box cart" action="<?php echo esc_url($nv_p->get_permalink()); ?>" method="post" enctype="multipart/form-data">
        <div class="qty" data-qty>
          <button type="button" data-qty-dec aria-label="Decrease quantity"><svg class="icon" aria-hidden="true"><use href="#i-minus"/></svg></button>
          <input type="number" name="quantity" inputmode="numeric" min="1" max="20" value="1" aria-label="Quantity">
          <button type="button" data-qty-inc aria-label="Increase quantity"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg></button>
        </div>
        <div class="buy-box__row">
          <button class="btn btn-outline" type="submit" name="add-to-cart" value="<?php echo esc_attr($nv_p->get_id()); ?>">Add to cart</button>
          <button class="btn btn-primary" type="submit" name="add-to-cart" value="<?php echo esc_attr($nv_p->get_id()); ?>" formaction="<?php echo esc_url(add_query_arg('nv_buy_now', '1', $nv_p->get_permalink())); ?>">Buy now</button>
        </div>
      </form>
      <?php else : ?>
      <p class="notice">This pack is currently out of stock. <a href="<?php echo esc_url(nv_shop_url()); ?>">See all packs</a>.</p>
      <?php endif; ?>

      <ul class="benefits">
        <li><span class="benefit-icon"><svg class="icon" aria-hidden="true"><use href="#i-tooth"/></svg></span>
          <span><strong>Supports Stronger Teeth</strong><span>Chewing and brushing with neem helps keep teeth clean, every day.</span></span></li>
        <li><span class="benefit-icon"><svg class="icon" aria-hidden="true"><use href="#i-heart"/></svg></span>
          <span><strong>Helps Keep Gums Healthy</strong><span>Soft, natural bristles are gentle on gums.</span></span></li>
        <li><span class="benefit-icon"><svg class="icon" aria-hidden="true"><use href="#i-wind"/></svg></span>
          <span><strong>Promotes Fresh Breath</strong><span>Neem naturally freshens your mouth.</span></span></li>
      </ul>
      <p class="mt-lg"><a class="link-arrow" href="<?php echo esc_url($nv_p ? $nv_p->get_permalink() : nv_shop_url()); ?>">View full product details <svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></a></p>
    </div>
  </div>
</section>

<!-- 5. Brand essence -->
<section class="section section--deep" id="why-neem" aria-labelledby="why-title">
  <svg class="deco-leaf" style="left:-60px;top:30px;transform:rotate(-12deg)" aria-hidden="true"><use href="#i-neem-leaf"/></svg>
  <div class="container">
    <div class="section-head reveal">
      <p class="eyebrow">Why neem</p>
      <h2 id="why-title">Rooted in nature, shaped by tradition</h2>
      <p>For centuries, neem has been part of India's everyday care rituals. We simply bring it back to your bathroom shelf, honestly and beautifully.</p>
    </div>
    <div class="pillars">
      <article class="card card--lift pillar reveal">
        <span class="pillar__num" aria-hidden="true">01</span>
        <div class="pillar__icon"><svg class="icon" aria-hidden="true"><use href="#i-leaf"/></svg></div>
        <h3>Natural</h3>
        <p>Rooted in the purity of neem and the goodness of nature. Nothing added, nothing taken away.</p>
      </article>
      <article class="card card--lift pillar reveal" style="--delay:.1s">
        <span class="pillar__num" aria-hidden="true">02</span>
        <div class="pillar__icon"><svg class="icon" aria-hidden="true"><use href="#i-diya"/></svg></div>
        <h3>Traditional</h3>
        <p>Inspired by time-tested Indian oral-care practices, passed down from our grandparents' mornings.</p>
      </article>
      <article class="card card--lift pillar reveal" style="--delay:.2s">
        <span class="pillar__num" aria-hidden="true">03</span>
        <div class="pillar__icon"><svg class="icon" aria-hidden="true"><use href="#i-smile"/></svg></div>
        <h3>Healthy</h3>
        <p>Encouraging natural oral-care habits that support everyday dental hygiene, for the whole family.</p>
      </article>
    </div>
  </div>
</section>

<!-- 6. How to use -->
<section class="section section--white" id="how-to-use" aria-labelledby="how-title">
  <div class="container">
    <div class="section-head reveal">
      <p class="eyebrow">How to use</p>
      <h2 id="how-title">Four simple steps to a fresher smile</h2>
      <p>Using a neem stick takes a minute to learn and becomes a calm morning ritual.</p>
    </div>
    <ol class="steps">
      <li class="step reveal">
        <div class="step__art"><svg aria-hidden="true"><use href="#s-rinse"/></svg><span class="step__num" aria-hidden="true">1</span></div>
        <h3>Rinse the stick</h3>
        <p>Wash the neem stick under clean running water.</p>
      </li>
      <li class="step reveal" style="--delay:.08s">
        <div class="step__art"><svg aria-hidden="true"><use href="#s-chew"/></svg><span class="step__num" aria-hidden="true">2</span></div>
        <h3>Chew one end</h3>
        <p>Gently chew one end until soft, brush-like bristles form.</p>
      </li>
      <li class="step reveal" style="--delay:.16s">
        <div class="step__art"><svg aria-hidden="true"><use href="#s-brush"/></svg><span class="step__num" aria-hidden="true">3</span></div>
        <h3>Brush gently</h3>
        <p>Brush your teeth and along the gums in soft, circular strokes. Rinse your mouth well.</p>
      </li>
      <li class="step reveal" style="--delay:.24s">
        <div class="step__art"><svg aria-hidden="true"><use href="#s-trim"/></svg><span class="step__num" aria-hidden="true">4</span></div>
        <h3>Trim and reuse</h3>
        <p>After a few days, trim off the used end and chew a fresh one.</p>
      </li>
    </ol>
    <div class="tip reveal">
      <svg class="icon" aria-hidden="true"><use href="#i-leaf"/></svg>
      <p><strong>Tip:</strong> Rinse the stick after each use and let it air-dry. When it gets too short to hold comfortably, it can go straight into your compost or garden soil.</p>
    </div>
  </div>
</section>

<!-- 7. Comparison -->
<section class="section" aria-labelledby="compare-title">
  <div class="container">
    <div class="section-head reveal">
      <p class="eyebrow">A gentle switch</p>
      <h2 id="compare-title">Neem stick vs. plastic toothbrush</h2>
      <p>Billions of plastic toothbrushes are thrown away every year. A neem stick returns to the earth.</p>
    </div>
    <div class="compare-wrap reveal">
      <table class="compare">
        <caption>A plastic toothbrush typically has a plastic handle and nylon bristles. Neem sticks are 100% plant-based.</caption>
        <thead>
          <tr>
            <th scope="col"><span class="sr-only">Feature</span></th>
            <th scope="col" class="is-us"><span class="compare__th-us">neemveda Neem Stick</span></th>
            <th scope="col">Plastic Toothbrush</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <th scope="row">Biodegradable</th>
            <td class="is-us"><span class="mark mark--yes"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg></span><span class="sr-only">Yes</span></td>
            <td><span class="mark mark--no"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></span><span class="sr-only">No</span></td>
          </tr>
          <tr>
            <th scope="row">Chemical-free</th>
            <td class="is-us"><span class="mark mark--yes"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg></span><span class="sr-only">Yes</span></td>
            <td><span class="mark mark--no"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></span><span class="sr-only">No</span></td>
          </tr>
          <tr>
            <th scope="row">No plastic waste</th>
            <td class="is-us"><span class="mark mark--yes"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg></span><span class="sr-only">Yes</span></td>
            <td><span class="mark mark--no"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></span><span class="sr-only">No</span></td>
          </tr>
          <tr>
            <th scope="row">Traditional heritage</th>
            <td class="is-us"><span class="mark mark--yes"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg></span><span class="sr-only">Yes</span></td>
            <td><span class="mark mark--no"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></span><span class="sr-only">No</span></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- 8. Story -->
<section class="section section--white" aria-labelledby="story-title">
  <div class="container story">
    <div class="story__media reveal">
      <img src="<?php echo esc_url(nv_asset('img/natural/story-neem-tree.jpg')); ?>" width="800" height="1000" loading="lazy" decoding="async"
           alt="The branches of a neem tree against a blue sky">
    </div>
    <div class="reveal" style="--delay:.1s">
      <p class="eyebrow">Our heritage</p>
      <h2 id="story-title">The story of the datun</h2>
      <p>Not so long ago, mornings across India began under a neem tree. A fresh twig, snapped and chewed into a soft brush, was the <em>datun</em>: a ritual shared by grandparents, farmers and schoolchildren alike.</p>
      <p>Ayurvedic texts describe <em>dantadhavana</em>, the cleaning of teeth with twigs of trees such as neem. Somewhere along the way, plastic took its place.</p>
      <p class="story__quote">neemveda brings the datun home again: carefully selected, cleaned and packed, ready for modern mornings.</p>
      <a class="link-arrow" href="<?php echo esc_url(nv_page_url('about', '/about/')); ?>">Read our story <svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></a>
    </div>
  </div>
</section>


<!-- 10. FAQ -->
<section class="section section--white" id="faq" aria-labelledby="faq-title">
  <div class="container">
    <div class="section-head reveal">
      <p class="eyebrow">Questions</p>
      <h2 id="faq-title">Frequently asked questions</h2>
    </div>
    <div class="faq reveal">
      <details>
        <summary>How should I store my neem sticks?<svg class="icon" aria-hidden="true"><use href="#i-chevron"/></svg></summary>
        <div class="faq__body"><p>Keep them in their box in a cool, dry place, away from direct sunlight and moisture. After each use, rinse the stick and let it air-dry standing upright. Avoid closed, damp containers.</p></div>
      </details>
      <details>
        <summary>How long does one neem stick last?<svg class="icon" aria-hidden="true"><use href="#i-chevron"/></svg></summary>
        <div class="faq__body"><p>One stick can usually be used for a few days. Trim off the used, bristled end and chew a fresh one as you go, until the stick becomes too short to hold comfortably. </p></div>
      </details>
      <details>
        <summary>Are neem sticks suitable for children?<svg class="icon" aria-hidden="true"><use href="#i-chevron"/></svg></summary>
        <div class="faq__body"><p>Older children can use neem sticks under adult supervision, once they can chew and brush gently on their own. Neem has a naturally bitter taste, which some children take a little time to enjoy. If in doubt, please check with your family dentist.</p></div>
      </details>
      <details>
        <summary>Do I need toothpaste with a neem stick?<svg class="icon" aria-hidden="true"><use href="#i-chevron"/></svg></summary>
        <div class="faq__body"><p>Traditionally, neem sticks are used on their own. You are welcome to continue with your usual toothpaste or tooth powder if you prefer. And please keep up your regular dental check-ups.</p></div>
      </details>
      <details>
        <summary>Where do you ship, and how long does delivery take?<svg class="icon" aria-hidden="true"><use href="#i-chevron"/></svg></summary>
        <div class="faq__body"><p>We ship across India. Orders are usually dispatched within <?php echo esc_html(nv_opt('dispatch_days')); ?> working days and delivered in <?php echo esc_html(nv_opt('delivery_days')); ?> working days, depending on your PIN code. You'll receive tracking details by email. See our <a href="<?php echo esc_url(nv_page_url('shipping-policy', '/shipping-policy/')); ?>">shipping policy</a> for details.</p></div>
      </details>
      <details>
        <summary>Is the packaging plastic-free?<svg class="icon" aria-hidden="true"><use href="#i-chevron"/></svg></summary>
        <div class="faq__body"><p>Yes. Our sticks are tied with natural jute twine and packed in a recyclable paper box. </p></div>
      </details>
    </div>
    <p class="center mt-lg muted">Still curious? <a href="<?php echo esc_url(nv_page_url('contact', '/contact/')); ?>">Get in touch</a> or email us at <a href="mailto:<?php echo esc_attr(nv_opt('email')); ?>"><?php echo esc_html(nv_opt('email')); ?></a>.</p>
  </div>
</section>

<!-- 11. Newsletter -->
<?php get_template_part('parts/newsletter'); ?>


<?php get_footer();
