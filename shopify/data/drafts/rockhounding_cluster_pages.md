# Rockhounding Cluster — Collection Page Build (DRAFT for review)

Targets the ~12,000 impr/month rock/geology query cluster (GSC Queries.csv), most of it
pos 6–18 with ~0% CTR. Three pages, each owning a sub-theme. Same playbook as the
gold-panning-kits rebuild: intent title/meta + above-grid intro + below-grid Q&A buyer
guide (question headings) + internal links in `link underlined-link` classes + (parent)
FAQPage JSON-LD. No blog content. Grounded in the real products.

Query map (impr / pos):
- KITS  -> "geology kits" 1848 @9.48, "geology kit" 587 @5.96, "rock hounding kit" 355 @4.25,
  "rockhounding kit" 269 @5.9, "geology equipment for beginners" 213 @4.54, "geology set" 137 @5.2
- PARENT-> "what is rockhounding" 1341 @7.41, "what is rock hounding" 575 @6.5,
  "rockhounding for beginners" 164 @8.36, "rockhounding guide" 28, "finding rocks" 169 @5.79
- ACCESS-> "rock hounding tools" 715 @7.81, "rockhounding tools" 588 @10.01, "rock pick" 537 @17.15,
  "rockhounding equipment" 308 @9.28, "rock chisel set" 288 @7.45, "rock hound" 210 @17.67,
  "rockhounding supplies" 201, "prospector tools" 179 @18.63, "prospectors hammer" 175 @12.49,
  "rockhounding bag" 139 @4.55, "rock classifier" 136 @4.22

Products (verified):
- rockhounding-kits (7): 40pc Deluxe Geology Rock Hounding Kit; 16pc Beginner Geology Rock
  Hounding Kit; 21pc Geology Rock Hounding Kit; 12pc Break Your Own Geodes Kit; 5LB Rough
  Gemstone Paydirt; Genuine Gemstone Paydirt Bags (12 Rock Types); 5lb Tumbled Rocks + Rough
  Gemstone Paydirt 14pc.
- rockhounding-accessories (10): 20oz Rock Pick Mining Hammer (11in pointed tip); Collapsible
  Magnetic Prospectors Pick Axe; 3pc Cold Steel Chisel Set; 13in Serrated Edge Digger;
  Heavy Duty Stackable Sifting Screens; 13 Pocket Musette Shoulder Tool Bag; Nylon Tool Bag w/
  strap; 4pc Aluminum Eye Loupe Set (2.5x-10x); 20pc Aluminum Storage Container Set; 2in
  Natural Testing Stone.

===============================================================================
## PAGE A — `rockhounding` (parent aggregate)  [owns "what is rockhounding"]
===============================================================================

### Title/meta — ALREADY LIVE (keep)
- title: `Rockhounding Kits, Tools & Accessories | ASR Outdoor`
- meta:  `Rockhounding kits, geology hammers, chisels, loupes, and tool bags from ASR Outdoor for digging, cracking, and examining rocks, minerals, and geodes.`

### Above-grid intro — ALREADY LIVE (keep, plain-text version pasted)

### BELOW-GRID buyer guide (Custom Liquid section under the grid)
```html
<div class="page-width" style="padding-top: 2rem; padding-bottom: 2rem;">
  <h2>Rockhounding: A Beginner's Guide</h2>

  <h3>What is rockhounding?</h3>
  <p>Rockhounding is the hobby of searching for and collecting rocks, minerals, gemstones, and fossils out in nature. It can be as simple as filling your pockets on a hike or as involved as splitting open geodes and tumbling your finds to a polish. All you really need to start is a way to break and pick up rock, something to carry it in, and a loupe to look closely at what you find.</p>

  <h3>What do you need to start rockhounding?</h3>
  <p>Most rockhounds carry a rock pick or geology hammer to break and pry rock, a few cold chisels for splitting along seams, a loupe to examine crystals, and a sturdy bag for hauling finds. The fastest way to get all of it is a complete <a href="/collections/rockhounding-kits" class="link underlined-link">rockhounding kit</a>; if you already have the basics, add individual <a href="/collections/rockhounding-accessories" class="link underlined-link">rockhounding tools and accessories</a> as you go.</p>

  <h3>Where do you find rocks and minerals?</h3>
  <p>Productive spots include creek and riverbeds, road cuts, gravel bars, beaches, deserts, and old quarry tailings. Always check local rules and get permission before collecting on private or protected land. Prefer to start at home? Crack open a <a href="/collections/geodes" class="link underlined-link">geode kit</a> or sift through <a href="/collections/gemstone-paydirt" class="link underlined-link">gemstone paydirt</a> to find real stones without the trip.</p>

  <h3>Should I buy a rockhounding kit or individual tools?</h3>
  <p>If you are just getting started, a <a href="/collections/rockhounding-kits" class="link underlined-link">complete kit</a> is the better value, it bundles the hammer, chisels, loupe, and bag in one box so nothing is missing on your first trip. If you already rockhound and just need to replace or upgrade a tool, shop the <a href="/collections/rockhounding-accessories" class="link underlined-link">accessories</a> individually.</p>

  <h3>How do you identify what you find?</h3>
  <p>A loupe magnifies crystals and grain structure so you can tell minerals apart, and a testing stone helps with simple streak and hardness checks in the field. Sort your haul by size with a sifting screen, then store the keepers in padded containers so the good pieces do not chip on the way home.</p>
</div>
```

### FAQPage JSON-LD (paste as a SECOND Custom Liquid section on the parent page)
```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {"@type":"Question","name":"What is rockhounding?","acceptedAnswer":{"@type":"Answer","text":"Rockhounding is the hobby of searching for and collecting rocks, minerals, gemstones, and fossils in nature. It can be as simple as collecting on a hike or as involved as splitting geodes and tumbling finds to a polish."}},
    {"@type":"Question","name":"What do you need to start rockhounding?","acceptedAnswer":{"@type":"Answer","text":"A rock pick or geology hammer to break and pry rock, a few cold chisels for splitting seams, a loupe to examine crystals, and a sturdy bag to carry finds. A complete rockhounding kit bundles all of these."}},
    {"@type":"Question","name":"Where do you find rocks and minerals?","acceptedAnswer":{"@type":"Answer","text":"Creek and riverbeds, road cuts, gravel bars, beaches, deserts, and old quarry tailings are productive. Always check local rules and get permission first. You can also find real stones at home with geode kits and gemstone paydirt."}},
    {"@type":"Question","name":"Should I buy a rockhounding kit or individual tools?","acceptedAnswer":{"@type":"Answer","text":"Beginners get the best value from a complete kit that bundles the hammer, chisels, loupe, and bag. Experienced rockhounds can buy individual tools to replace or upgrade what they already own."}}
  ]
}
</script>
```

===============================================================================
## PAGE B — `rockhounding-kits`  [owns "geology kit(s)/set/equipment"]
===============================================================================

### Title/meta — RECOMMEND REVISION (currently live: "Rockhounding Kits: Geology Tool & Geode Kits")
Lead with the bigger term ("geology kit(s)" ~2,800 impr vs "rockhounding kit" ~620):
- **title:** `Geology & Rockhounding Kits with Tools & Geodes | ASR Outdoor`  (58)
- **meta:**  `Complete geology and rockhounding kits from ASR Outdoor: rock hammer, chisels, loupe, and tool bag, plus break-your-own geode kits and gemstone paydirt.` (151)

### Above-grid intro (Description field, plain text or HTML)
```html
<p>A complete kit is the easiest way into rockhounding, and ASR Outdoor builds geology kits for every level. Each kit pairs the core tools, a rock pick or geology hammer, chisels, a magnifying loupe, safety glasses, and a carry bag, so you can break, pry, and examine rock from your very first trip. New collectors can start with the 16pc beginner set, while serious rockhounds reach for the 21pc and 40pc deluxe kits with more tools and a bigger bag. Want a guaranteed find? Add a break-your-own geode kit or a bag of gemstone paydirt below.</p>
```

### BELOW-GRID buyer guide (Custom Liquid under the grid)
```html
<div class="page-width" style="padding-top: 2rem; padding-bottom: 2rem;">
  <h2>Choosing a Geology &amp; Rockhounding Kit</h2>

  <h3>What comes in a rockhounding or geology kit?</h3>
  <p>Our kits include the essentials for finding and examining rock: a rock pick or geology hammer to break and pry, cold chisels for splitting seams, a magnifying loupe to inspect crystals, safety glasses, and a tool bag to carry it all. The larger kits add more tools and a deluxe bag.</p>

  <h3>Which kit is right for a beginner?</h3>
  <p>The 16pc Beginner Geology Rock Hounding Kit covers everything a first-timer needs without overspending, the hammer, chisels, loupe, and bag in one box. It is also a great gift for kids and new collectors getting into the hobby.</p>

  <h3>What is the best kit for serious rockhounds?</h3>
  <p>Step up to the 21pc or the 40pc Deluxe Geology Rock Hounding Kit. You get additional tools and a larger musette-style carry bag built for longer days and bigger hauls in the field.</p>

  <h3>Are these geology kits good for kids?</h3>
  <p>Yes, with adult supervision for the hammer and chisels. For younger collectors, the hands-on fun of a <a href="/collections/geodes" class="link underlined-link">break-your-own geode kit</a> or a bag of <a href="/collections/gemstone-paydirt" class="link underlined-link">gemstone paydirt</a> is a perfect, lower-tool way to start finding real stones.</p>

  <h3>Kit vs. paydirt: what is the difference?</h3>
  <p>A geology kit gives you the tools to go find rock; <a href="/collections/gemstone-paydirt" class="link underlined-link">gemstone paydirt</a> and <a href="/collections/geodes" class="link underlined-link">geode kits</a> give you the material to search at home. Many collectors buy both, the kit for trips and paydirt for a guaranteed find on the kitchen table. Already have your tools? Round out your setup with individual <a href="/collections/rockhounding-accessories" class="link underlined-link">rockhounding accessories</a>.</p>
</div>
```

===============================================================================
## PAGE C — `rockhounding-accessories`  [owns "rock pick", "rock chisel set", "tools"]
===============================================================================

### Title/meta — ALREADY LIVE (keep)
- title: `Rockhounding Tools & Accessories | ASR Outdoor`
- meta:  `Rockhounding tools and accessories from ASR Outdoor: rock pick hammers, cold chisels, magnetic pick axe, loupes, testing stone, sifting screens, and tool bags.`

### Above-grid intro (Description field) — RECOMMEND adding/confirming
```html
<p>Build your rockhounding setup tool by tool. ASR Outdoor stocks the individual gear that fills out a collector's bag: rock pick mining hammers and a magnetic pick axe for breaking and prying, cold steel chisels for splitting seams, sifting screens to sort material, loupes and a testing stone for examining finds, and rugged tool bags to carry it all. Whether you are replacing a worn pick or adding your first chisel set, you will find each rockhounding tool matched to a step in finding, breaking, and identifying rock below.</p>
```

### BELOW-GRID buyer guide (Custom Liquid under the grid)
```html
<div class="page-width" style="padding-top: 2rem; padding-bottom: 2rem;">
  <h2>Rockhounding Tools &amp; Accessories Explained</h2>

  <h3>What tools do you need for rockhounding?</h3>
  <p>The core kit is a rock pick or geology hammer for breaking and prying, a set of cold chisels for splitting along seams, a loupe for examining crystals, a sifting screen to sort material, and a sturdy bag to carry your finds. Add a testing stone and storage containers as your collection grows. Prefer to buy it all at once? See our complete <a href="/collections/rockhounding-kits" class="link underlined-link">rockhounding kits</a>.</p>

  <h3>What is a rock pick used for, and how is it different from a regular hammer?</h3>
  <p>A rock pick mining hammer has a flat striking face on one end and a pointed or chisel tip on the other, so you can both break rock and pry pieces loose, something a standard claw hammer cannot do. Our 20oz pick has an 11-inch handle and a pointed tip for digging crystals out of pockets, and the collapsible magnetic pick axe packs down for travel.</p>

  <h3>Which chisels do I need for splitting rock?</h3>
  <p>A cold steel chisel set lets you work a crack or seam and split rock cleanly instead of shattering it. Our 3pc heavy-duty set covers the common widths for most fieldwork. Always wear eye protection when striking rock.</p>

  <h3>What is the best bag for rockhounding?</h3>
  <p>You want something rugged with a shoulder strap so your hands stay free on the trail. The 13-pocket musette shoulder bag keeps tools organized, while the weather-resistant nylon tool bag hauls a heavier load of finds.</p>

  <h3>How do I examine and store what I find?</h3>
  <p>A 4pc aluminum loupe set (2.5x to 10x) magnifies crystal and grain structure so you can identify minerals, and a natural testing stone helps with simple field checks. Sort by size with a stackable sifting screen, then protect the keepers in a 20pc aluminum storage container set so nothing chips on the way home.</p>
</div>
```

===============================================================================
## DEPLOY STEPS
===============================================================================
1. Title/meta revision for `rockhounding-kits` (Page B) -> run the apply script
   (handle rockhounding-kits) when approved. Pages A and C titles/metas already live.
2. Above-grid intros: paste Page B + Page C intros into each collection's Description.
3. Below-grid Q&A: add a Custom Liquid section under the grid on each of the 3 collections,
   paste the matching block, drag below the product grid.
4. FAQPage JSON-LD: add as a SECOND Custom Liquid section on the `rockhounding` parent only.
5. Re-check the cluster positions in GSC ~2-3 weeks after deploy.
