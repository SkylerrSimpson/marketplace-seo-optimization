<?php

declare(strict_types=1);

/**
 * Maps Amazon SP-API attribute names to Usurper CSV column names.
 *
 * Each value is an ordered list of Usurper columns to try, highest-preference
 * first. The resolver returns the first column whose value is non-empty.
 *
 * bullet_point is multi-source: ANY non-empty feature column counts as a hit
 * because Usurper splits bullets across feature01–feature05.
 *
 * attr.amazon_* convention
 * ─────────────────────────
 * Any Amazon SP-API attribute NOT listed here is mapped at runtime to the
 * Usurper column  attr.amazon_{attribute_name}  (e.g., the attribute
 * supplier_declared_dg_hz_regulation maps to
 * attr.amazon_supplier_declared_dg_hz_regulation). Usurper treats the attr.*
 * prefix as a custom product attribute and either finds the matching model or
 * creates it dynamically on import. After the first project_to_usurper import
 * the value persists in Usurper, so subsequent gap-fill runs classify the
 * attribute as "fillable" rather than "needs_authoring" — the AI cost
 * collapses to zero on re-runs.
 *
 * Add an explicit entry here only when you need to map an Amazon attribute to
 * an existing Usurper column that does not follow the attr.amazon_* name.
 */
return [
    // ── Identity / title ───────────────────────────────────────────────────
    'item_name'                   => ['attr.title_amazon', 'name'],
    'brand'                       => ['attr.brand_amazon', 'attr.brand'],
    'manufacturer'                => ['attr.manufacturer_amazon', 'attr.manufacturer'],
    'model_number'                => ['attr.mpn_amazon', 'attr.mpn', 'attr.model'],
    'part_number'                 => ['attr.mpn_amazon', 'attr.mpn'],
    'other_product_id'            => ['attr.amazon_upc', 'attr.upc_amazon', 'attr.upc', 'attr.ean'],
    'standard_product_id'         => ['attr.amazon_upc', 'attr.upc_amazon', 'attr.upc', 'attr.ean'],

    // ── Copy / content ─────────────────────────────────────────────────────
    'product_description'         => ['attr.short_description', 'attr.description', 'description'],
    'bullet_point'                => ['attr.feature01', 'attr.feature02', 'attr.feature03', 'attr.feature04', 'attr.feature05'],
    'generic_keyword'             => ['attr.search_terms_amazon'],
    'item_type_name'              => ['attr.item_type_name_amazon', 'attr.item_type_amazon'],
    'item_type_keyword'           => ['attr.item_type_amazon'],

    // ── Appearance ─────────────────────────────────────────────────────────
    'color'                       => ['attr.amazon_color_override', 'attr.amazon_color', 'attr.color'],
    'color_name'                  => ['attr.amazon_color_override', 'attr.amazon_color', 'attr.color'],
    'material'                    => ['attr.material_amazon', 'attr.material'],
    'material_type'               => ['attr.material_type_amazon', 'attr.material_amazon', 'attr.material'],
    'outer_material_type'         => ['attr.outer_material_type_amazon'],
    'fill_material_type'          => ['attr.fill_material_type_amazon'],
    'cover_material_type'         => ['attr.cover_material_type_amazon'],
    'frame_material'              => ['attr.frame_material_type_amazon', 'attr.frame_material'],
    'frame_material_type'         => ['attr.frame_material_type_amazon', 'attr.frame_material'],
    'handle_material'             => ['attr.handle_material_amazon'],
    'blade_material_type'         => ['attr.blade_material_type_amazon'],
    'finish_type'                 => ['attr.finish_type_amazon'],
    'finish_types'                => ['attr.finish_types_amazon'],
    'pattern'                     => ['attr.pattern_name_amazon', 'attr.pattern'],
    'pattern_name'                => ['attr.pattern_name_amazon', 'attr.pattern'],
    'style'                       => ['attr.style_name_amazon', 'attr.style_amazon', 'attr.style'],
    'style_name'                  => ['attr.style_name_amazon', 'attr.style_amazon', 'attr.style'],
    'size'                        => ['attr.size_amazon', 'attr.size'],
    'size_name'                   => ['attr.size_amazon', 'attr.size'],
    'shade'                       => ['attr.shade'],
    'lens_color'                  => ['attr.lens_color'],
    'theme'                       => ['attr.theme_amazon', 'attr.theme'],
    'character'                   => ['attr.character_amazon', 'attr.character'],

    // ── Dimensions / weight ────────────────────────────────────────────────
    'item_length'                 => ['attr.length_inches', 'attr.length_amazon'],
    'item_width'                  => ['attr.width_inches', 'attr.width_amazon'],
    'item_height'                 => ['attr.height_inches'],
    'item_weight'                 => ['attr.weight_lb', 'attr.item_weight_amazon'],
    'item_volume'                 => ['attr.item_volume_amazon'],
    'item_display_depth'          => ['attr.item_display_depth_amazon'],
    'item_display_diameter'       => ['attr.item_display_diameter_amazon'],
    'item_length_longer_edge'     => ['attr.item_length_longer_edge_amazon'],
    'item_width_shorter_edge'     => ['attr.item_width_shorter_edge_amazon'],

    // ── Counts / quantities ────────────────────────────────────────────────
    'number_of_items'             => ['attr.number_of_items_amazon'],
    'num_pieces'                  => ['attr.num_pieces_amazon', 'attr.number_of_pieces_amazon', 'attr.pieces'],
    'number_of_pieces'            => ['attr.number_of_pieces_amazon', 'attr.num_pieces_amazon', 'attr.pieces'],
    'unit_count'                  => ['attr.unit_count_amazon'],
    'unit_count_type'             => ['attr.unit_count_type_amazon'],
    'number_of_speeds'            => ['attr.number_of_speeds_amazon'],
    'number_of_wheels'            => ['attr.number_of_wheels_amazon'],
    'number_of_pockets'           => ['attr.number_of_pockets_amazon'],
    'number_of_light_sources'     => ['attr.number_of_light_sources_amazon'],
    'capacity'                    => ['attr.capacity_amazon', 'attr.capacity'],

    // ── Electrical / power ─────────────────────────────────────────────────
    'voltage'                     => ['attr.voltage_amazon'],
    'wattage'                     => ['attr.wattage_amazon', 'attr.wattage'],
    'power_source_type'           => ['attr.power_source_type_amazon', 'attr.power_source'],
    'light_source_type'           => ['attr.light_source_type_amazon'],
    'connectivity_protocol'       => ['attr.connectivity_protocol_amazon'],
    'display_type'                => ['attr.display_type_amazon'],

    // ── Demographics / audience ────────────────────────────────────────────
    'target_gender'               => ['attr.target_gender_amazon', 'attr.gender'],
    'department'                  => ['attr.department_name_amazon', 'attr.department_amazon'],
    'department_name'             => ['attr.department_name_amazon', 'attr.department_amazon'],
    'age_range_description'       => ['attr.age_range_description_amazon'],
    'target_audience'             => ['attr.target_audience_amazon'],
    'esrb_age_rating'             => ['attr.esrb_age_rating_amazon'],

    // ── Use / function ─────────────────────────────────────────────────────
    'occasion'                    => ['attr.occasion_type_amazon', 'attr.occasion'],
    'occasion_type'               => ['attr.occasion_type_amazon', 'attr.occasion'],
    'sport_type'                  => ['attr.sport_type_amazon'],
    'specific_uses_for_product'   => ['attr.specific_uses_for_product_amazon', 'attr.specific_uses_amazon'],
    'specific_uses'               => ['attr.specific_uses_amazon'],
    'used_for'                    => ['attr.used_for_amazon'],
    'included_components'         => ['attr.included_components_amazon'],
    'special_features'            => ['attr.special_features_amazon'],
    'is_assembly_required'        => ['attr.is_assembly_required_amazon'],
    'import_designation'          => ['attr.import_designation_amazon'],
    'country_of_origin'           => ['attr.country_of_origin'],
    'mounting_type'               => ['attr.mounting_type_amazon'],
    'installation_type'           => ['attr.installation_type_amazon'],
    'compatible_devices'          => ['attr.compatible_devices_amazon'],
    'compatible_brand'            => ['attr.compatible_brand'],
    'compatible_model'            => ['attr.compatible_model'],
    'hand_orientation'            => ['attr.hand_orientation_amazon'],
    'movement_type'               => ['attr.movement_type_amazon'],
    'water_resistance_level'      => ['attr.water_resistance_level_amazon'],
    'reusability'                 => ['attr.reusability_amazon'],
    'customer_package_type'       => ['attr.customer_package_type_amazon'],
    'closure_type'                => ['attr.closure_type_amazon'],
    'thread_count'                => ['attr.thread_count_amazon'],
    'shank_type'                  => ['attr.shank_type_amazon'],
    'item_form'                   => ['attr.item_form_amazon'],
    'item_shape'                  => ['attr.item_shape_amazon'],
    'language'                    => ['attr.language_amazon'],
    'variation_theme'             => ['attr.variation_theme_amazon'],

    // ── Health / beauty ────────────────────────────────────────────────────
    'skin_type'                   => ['attr.skin_type_amazon'],
    'hair_type'                   => ['attr.hair_type_amazon'],
    'ingredients'                 => ['attr.ingredients_amazon'],
    'special_ingredients'         => ['attr.special_ingredients_amazon'],
    'product_benefit'             => ['attr.product_benefit_amazon'],
    'item_hardness'               => ['attr.item_hardness_amazon'],
];
