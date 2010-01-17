<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2009 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Item_Model extends ORM_MPTT {
  protected $children = 'items';
  protected $sorting = array();
  protected $data_file = null;

  var $rules = array(
    "name"        => array("rules" => array("length[0,255]", "required")),
    "title"       => array("rules" => array("length[0,255]", "required")),
    "slug"        => array("rules" => array("length[0,255]", "required")),
    "description" => array("rules" => array("length[0,65535]")),
    "type"        => array("rules" => array("Item_Model::valid_type")),
  );

  /**
   * Add a set of restrictions to any following queries to restrict access only to items
   * viewable by the active user.
   * @chainable
   */
  public function viewable() {
    return item::viewable($this);
  }

  /**
   * Is this item an album?
   * @return true if it's an album
   */
  public function is_album() {
    return $this->type == 'album';
  }

  /**
   * Is this item a photo?
   * @return true if it's a photo
   */
  public function is_photo() {
    return $this->type == 'photo';
  }

  /**
   * Is this item a movie?
   * @return true if it's a movie
   */
  public function is_movie() {
    return $this->type == 'movie';
  }

  public function delete() {
    $old = clone $this;
    module::event("item_before_delete", $this);

    $parent = $this->parent();
    if ($parent->album_cover_item_id == $this->id) {
      item::remove_album_cover($parent);
    }

    $path = $this->file_path();
    $resize_path = $this->resize_path();
    $thumb_path = $this->thumb_path();

    parent::delete();
    if (is_dir($path)) {
      // Take some precautions against accidentally deleting way too much
      $delete_resize_path = dirname($resize_path);
      $delete_thumb_path = dirname($thumb_path);
      if ($delete_resize_path == VARPATH . "resizes" ||
          $delete_thumb_path == VARPATH . "thumbs" ||
          $path == VARPATH . "albums") {
        throw new Exception(
          "@todo DELETING_TOO_MUCH ($delete_resize_path, $delete_thumb_path, $path)");
      }
      @dir::unlink($path);
      @dir::unlink($delete_resize_path);
      @dir::unlink($delete_thumb_path);
    } else {
      @unlink($path);
      @unlink($resize_path);
      @unlink($thumb_path);
    }

    module::event("item_deleted", $old);
  }

  /**
   * Move this item to the specified target.
   * @chainable
   * @param   Item_Model $target  Target item (must be an album)
   * @return  ORM_MPTT
   */
  function move_to($target) {
    if (!$target->is_album()) {
      throw new Exception("@todo INVALID_MOVE_TYPE $target->type");
    }

    if (file_exists($target_file = "{$target->file_path()}/$this->name")) {
      throw new Exception("@todo INVALID_MOVE_TARGET_EXISTS: $target_file");
    }

    if ($this->id == 1) {
      throw new Exception("@todo INVALID_SOURCE root album");
    }

    $original_path = $this->file_path();
    $original_resize_path = $this->resize_path();
    $original_thumb_path = $this->thumb_path();
    $original_parent = $this->parent();

    parent::move_to($target, true);
    model_cache::clear();
    $this->relative_path_cache = null;

    rename($original_path, $this->file_path());
    if ($this->is_album()) {
      @rename(dirname($original_resize_path), dirname($this->resize_path()));
      @rename(dirname($original_thumb_path), dirname($this->thumb_path()));
      db::build()
        ->update("items")
        ->set("relative_path_cache", null)
        ->set("relative_url_cache", null)
        ->where("left_ptr", ">", $this->left_ptr)
        ->where("right_ptr", "<", $this->right_ptr)
        ->execute();
    } else {
      @rename($original_resize_path, $this->resize_path());
      @rename($original_thumb_path, $this->thumb_path());
    }

    module::event("item_moved", $this, $original_parent);
    return $this;
  }

  /**
   * Rename the underlying file for this item to a new name and move all related files.
   *
   * @chainable
   */
  private function rename($new_name) {
    $old_relative_path = urldecode($this->original()->relative_path());
    $new_relative_path = dirname($old_relative_path) . "/" . $new_name;
    if (file_exists(VARPATH . "albums/$new_relative_path")) {
      throw new Exception("@todo INVALID_RENAME_FILE_EXISTS: $new_relative_path");
    }

    @rename(VARPATH . "albums/$old_relative_path", VARPATH . "albums/$new_relative_path");
    @rename(VARPATH . "resizes/$old_relative_path", VARPATH . "resizes/$new_relative_path");
    if ($this->is_movie()) {
      // Movie thumbnails have a .jpg extension
      $old_relative_thumb_path = preg_replace("/...$/", "jpg", $old_relative_path);
      $new_relative_thumb_path = preg_replace("/...$/", "jpg", $new_relative_path);
      @rename(VARPATH . "thumbs/$old_relative_thumb_path",
              VARPATH . "thumbs/$new_relative_thumb_path");
    } else {
      @rename(VARPATH . "thumbs/$old_relative_path", VARPATH . "thumbs/$new_relative_path");
    }

    return $this;
  }

  /**
   * Specify the path to the data file associated with this item.  To actually associate it,
   * you still have to call save().
   */
  public function set_data_file($data_file) {
    $this->data_file = $data_file;
  }

  /**
   * Return the server-relative url to this item, eg:
   *   /gallery3/index.php/BobsWedding?page=2
   *   /gallery3/index.php/BobsWedding/Eating-Cake.jpg
   *
   * @param string $query the query string (eg "show=3")
   */
  public function url($query=null) {
    $url = url::site($this->relative_url());
    if ($query) {
      $url .= "?$query";
    }
    return $url;
  }

  /**
   * Return the full url to this item, eg:
   *   http://example.com/gallery3/index.php/BobsWedding?page=2
   *   http://example.com/gallery3/index.php/BobsWedding/Eating-Cake.jpg
   *
   * @param string $query the query string (eg "show=3")
   */
  public function abs_url($query=null) {
    $url = url::abs_site($this->relative_url());
    if ($query) {
      $url .= "?$query";
    }
    return $url;
  }

  /**
   * album: /var/albums/album1/album2
   * photo: /var/albums/album1/album2/photo.jpg
   */
  public function file_path() {
    return VARPATH . "albums/" . urldecode($this->relative_path());
  }

  /**
   * album: http://example.com/gallery3/var/resizes/album1/
   * photo: http://example.com/gallery3/var/albums/album1/photo.jpg
   */
  public function file_url($full_uri=false) {
    $relative_path = "var/albums/" . $this->relative_path();
    return ($full_uri ? url::abs_file($relative_path) : url::file($relative_path))
      . "?m={$this->updated}";
  }

  /**
   * album: /var/resizes/album1/.thumb.jpg
   * photo: /var/albums/album1/photo.thumb.jpg
   */
  public function thumb_path() {
    $base = VARPATH . "thumbs/" . urldecode($this->relative_path());
    if ($this->is_photo()) {
      return $base;
    } else if ($this->is_album()) {
      return $base . "/.album.jpg";
    } else if ($this->is_movie()) {
      // Replace the extension with jpg
      return preg_replace("/...$/", "jpg", $base);
    }
  }

  /**
   * Return true if there is a thumbnail for this item.
   */
  public function has_thumb() {
    return $this->thumb_width && $this->thumb_height;
  }

  /**
   * album: http://example.com/gallery3/var/resizes/album1/.thumb.jpg
   * photo: http://example.com/gallery3/var/albums/album1/photo.thumb.jpg
   */
  public function thumb_url($full_uri=false) {
    $cache_buster = "?m={$this->updated}";
    $relative_path = "var/thumbs/" . $this->relative_path();
    $base = ($full_uri ? url::abs_file($relative_path) : url::file($relative_path));
    if ($this->is_photo()) {
      return $base . $cache_buster;
    } else if ($this->is_album()) {
      return $base . "/.album.jpg" . $cache_buster;
    } else if ($this->is_movie()) {
      // Replace the extension with jpg
      $base = preg_replace("/...$/", "jpg", $base);
      return $base . $cache_buster;
    }
  }

  /**
   * album: /var/resizes/album1/.resize.jpg
   * photo: /var/albums/album1/photo.resize.jpg
   */
  public function resize_path() {
    return VARPATH . "resizes/" . urldecode($this->relative_path()) .
      ($this->is_album() ? "/.album.jpg" : "");
  }

  /**
   * album: http://example.com/gallery3/var/resizes/album1/.resize.jpg
   * photo: http://example.com/gallery3/var/albums/album1/photo.resize.jpg
   */
  public function resize_url($full_uri=false) {
    $relative_path = "var/resizes/" . $this->relative_path();
    return ($full_uri ? url::abs_file($relative_path) : url::file($relative_path)) .
      ($this->is_album() ? "/.album.jpg" : "")
      . "?m={$this->updated}";
  }

  /**
   * Rebuild the relative_path_cache and relative_url_cache.
   */
  private function _build_relative_caches() {
    $names = array();
    $slugs = array();
    foreach (db::build()
             ->select(array("name", "slug"))
             ->from("items")
             ->where("left_ptr", "<=", $this->left_ptr)
             ->where("right_ptr", ">=", $this->right_ptr)
             ->where("id", "<>", 1)
             ->order_by("left_ptr", "ASC")
             ->execute() as $row) {
      // Don't encode the names segment
      $names[] = rawurlencode($row->name);
      $slugs[] = rawurlencode($row->slug);
    }
    $this->relative_path_cache = implode($names, "/");
    $this->relative_url_cache = implode($slugs, "/");
    return $this;
  }

  /**
   * Return the relative path to this item's file.  Note that the components of the path are
   * urlencoded so if you want to use this as a filesystem path, you need to call urldecode
   * on it.
   * @return string
   */
  public function relative_path() {
    if (!$this->loaded()) {
      return;
    }

    if (!isset($this->relative_path_cache)) {
      $this->_build_relative_caches()->save();
    }
    return $this->relative_path_cache;
  }

  /**
   * Return the relative url to this item's file.
   * @return string
   */
  public function relative_url() {
    if (!$this->loaded()) {
      return;
    }

    if (!isset($this->relative_url_cache)) {
      $this->_build_relative_caches()->save();
    }
    return $this->relative_url_cache;
  }

  /**
   * @see ORM::__get()
   */
  public function __get($column) {
    if ($column == "owner") {
      // This relationship depends on an outside module, which may not be present so handle
      // failures gracefully.
      try {
        return identity::lookup_user($this->owner_id);
      } catch (Exception $e) {
        return null;
      }
    } else {
      return parent::__get($column);
    }
  }

  /**
   * Handle any business logic necessary to create an item.
   * @see ORM::save()
   *
   * @return ORM Item_Model
   */
  public function save() {
    $significant_changes = $this->changed;
    unset($significant_changes["view_count"]);
    unset($significant_changes["relative_url_cache"]);
    unset($significant_changes["relative_path_cache"]);

    if (!empty($this->changed) && $significant_changes) {
      $this->updated = time();
      if (!$this->loaded()) {
        // Create a new item.  Use whatever fields are set, and specify defaults for the rest.
        $this->created = $this->updated;
        $this->weight = item::get_max_weight();
        $this->rand_key = ((float)mt_rand()) / (float)mt_getrandmax();
        $this->thumb_dirty = 1;
        $this->resize_dirty = 1;
        if (empty($this->sort_column)) {
          $this->sort_column = "created";
        }
        if (empty($this->sort_order)) {
          $this->sort_order = "ASC";
        }
        if (empty($this->owner_id)) {
          $this->owner_id = identity::active_user()->id;
        }

        // Make an url friendly slug from the name, if necessary
        if (empty($this->slug)) {
          $tmp = pathinfo($this->name, PATHINFO_FILENAME);
          $tmp = preg_replace("/[^A-Za-z0-9-_]+/", "-", $tmp);
          $this->slug = trim($tmp, "-");
        }

        // Get the width, height and mime type from our data file for photos and movies.
        if ($this->is_movie() || $this->is_photo()) {
          $pi = pathinfo($this->data_file);

          if ($this->is_photo()) {
            $image_info = getimagesize($this->data_file);
            $this->width = $image_info[0];
            $this->height = $image_info[1];
            $this->mime_type = $image_info["mime"];

            // Force an extension onto the name if necessary
            if (empty($pi["extension"])) {
              $pi["extension"] = image_type_to_extension($image_info[2], false);
              $this->name .= "." . $pi["extension"];
            }
          } else {
            list ($this->width, $this->height) = movie::getmoviesize($this->data_file);

            // No extension?  Assume FLV.
            if (empty($pi["extension"])) {
              $pi["extension"] = "flv";
              $this->name .= "." . $pi["extension"];
            }

            $this->mime_type = strtolower($pi["extension"]) == "mp4" ? "video/mp4" : "video/x-flv";
          }
        }

        // Randomize the name or slug if there's a conflict.  Preserve the extension.
        // @todo Improve this.  Random numbers are not user friendly
        $base_name = pathinfo($this->name, PATHINFO_FILENAME);
        $base_ext = pathinfo($this->name, PATHINFO_EXTENSION);
        $base_slug = $this->slug;
        while (ORM::factory("item")
               ->where("parent_id", "=", $this->parent_id)
               ->and_open()
               ->where("name", "=", $this->name)
               ->or_where("slug", "=", $this->slug)
               ->close()
               ->find()->id) {
          $rand = rand();
          if ($base_ext) {
            $this->name = "$base_name-$rand.$base_ext";
          } else {
            $this->name = "$base_name-$rand";
          }
          $this->slug = "$base_slug-$rand";
        }

        parent::save();

        // Build our url caches, then save again.  We have to do this after it's already been
        // saved once because we use only information from the database to build the paths.  If we
        // could depend on a save happening later we could defer this 2nd save.
        $this->_build_relative_caches();
        parent::save();

        // Take any actions that we can only do once all our paths are set correctly after saving.
        switch ($this->type) {
        case "album":
          mkdir($this->file_path());
          mkdir(dirname($this->thumb_path()));
          mkdir(dirname($this->resize_path()));
          break;

        case "photo":
        case "movie":
          // The thumb or resize may already exist in the case where a movie and a photo generate
          // a thumbnail of the same name (eg, foo.flv movie and foo.jpg photo will generate
          // foo.jpg thumbnail).  If that happens, randomize and save again.
          if (file_exists($this->resize_path()) ||
              file_exists($this->thumb_path())) {
            $pi = pathinfo($this->name);
            $this->name = $pi["filename"] . "-" . rand() . "." . $pi["extension"];
            parent::save();
          }

          copy($this->data_file, $this->file_path());
          break;
        }

        // This will almost definitely trigger another save, so put it at the end so that we're
        // tail recursive.
        module::event("item_created", $this);
      } else {
        // Update an existing item
        if ($this->original()->name != $this->name) {
          $this->rename($this->name);
          $this->relative_path_cache = null;
        }

        if ($this->original()->slug != $this->slug) {
          // Clear the relative url cache for this item and all children
          $this->relative_url_cache = null;
        }

        // Changing the name or the slug ripples downwards
        if ($this->is_album() &&
            ($this->original()->name != $this->name ||
             $this->original()->slug != $this->slug)) {
          db::build()
            ->update("items")
            ->set("relative_url_cache", null)
            ->set("relative_path_cache", null)
            ->where("left_ptr", ">", $this->left_ptr)
            ->where("right_ptr", "<", $this->right_ptr)
            ->execute();
        }
        $original = clone $this->original();
        parent::save();
        module::event("item_updated", $original, $this);
      }
    } else if (!empty($this->changed)) {
      // Insignificant changes only.  Don't fire events or do any special checking to try to keep
      // this lightweight.
      parent::save();
    }

    return $this;
  }

  /**
   * Return the Item_Model representing the cover for this album.
   * @return Item_Model or null if there's no cover
   */
  public function album_cover() {
    if (!$this->is_album()) {
      return null;
    }

    if (empty($this->album_cover_item_id)) {
      return null;
    }

    try {
      return model_cache::get("item", $this->album_cover_item_id);
    } catch (Exception $e) {
      // It's possible (unlikely) that the item was deleted, if so keep going.
      return null;
    }
  }

  /**
   * Find the position of the given child id in this album.  The resulting value is 1-indexed, so
   * the first child in the album is at position 1.
   */
  public function get_position($child, $where=array()) {
    if ($this->sort_order == "DESC") {
      $comp = ">";
    } else {
      $comp = "<";
    }
    $db = db::build();

    // If the comparison column has NULLs in it, we can't use comparators on it and will have to
    // deal with it the hard way.
    $count = $db->from("items")
      ->where("parent_id", "=", $this->id)
      ->where($this->sort_column, "IS", null)
      ->merge_where($where)
      ->count_records();

    if (empty($count)) {
      // There are no NULLs in the sort column, so we can just use it directly.
      $sort_column = $this->sort_column;

      $position = $db->from("items")
        ->where("parent_id", "=", $this->id)
        ->where($sort_column, $comp, $child->$sort_column)
        ->merge_where($where)
        ->count_records();

      // We stopped short of our target value in the sort (notice that we're using a < comparator
      // above) because it's possible that we have duplicate values in the sort column.  An
      // equality check would just arbitrarily pick one of those multiple possible equivalent
      // columns, which would mean that if you choose a sort order that has duplicates, it'd pick
      // any one of them as the child's "position".
      //
      // Fix this by doing a 2nd query where we iterate over the equivalent columns and add them to
      // our base value.
      foreach ($db
               ->select("id")
               ->from("items")
               ->where("parent_id", "=", $this->id)
               ->where($sort_column, "=", $child->$sort_column)
               ->merge_where($where)
               ->order_by(array("id" => "ASC"))
               ->execute() as $row) {
        $position++;
        if ($row->id == $child->id) {
          break;
        }
      }
    } else {
      // There are NULLs in the sort column, so we can't use MySQL comparators.  Fall back to
      // iterating over every child row to get to the current one.  This can be wildly inefficient
      // for really large albums, but it should be a rare case that the user is sorting an album
      // with null values in the sort column.
      //
      // Reproduce the children() functionality here using Database directly to avoid loading the
      // whole ORM for each row.
      $order_by = array($this->sort_column => $this->sort_order);
      // Use id as a tie breaker
      if ($this->sort_column != "id") {
        $order_by["id"] = "ASC";
      }

      $position = 0;
      foreach ($db->select("id")
               ->from("items")
               ->where("parent_id", "=", $this->id)
               ->merge_where($where)
               ->order_by($order_by)
               ->execute() as $row) {
        $position++;
        if ($row->id == $child->id) {
          break;
        }
      }
    }

    return $position;
  }

  /**
   * Return an <img> tag for the thumbnail.
   * @param array $extra_attrs  Extra attributes to add to the img tag
   * @param int (optional) $max Maximum size of the thumbnail (default: null)
   * @param boolean (optional) $center_vertically Center vertically (default: false)
   * @return string
   */
  public function thumb_img($extra_attrs=array(), $max=null, $center_vertically=false) {
    list ($height, $width) = $this->scale_dimensions($max);
    if ($center_vertically && $max) {
      // The constant is divide by 2 to calculate the file and 10 to convert to em
      $margin_top = ($max - $height) / 20;
      $extra_attrs["style"] = "margin-top: {$margin_top}em";
      $extra_attrs["title"] = $this->title;
    }
    $attrs = array_merge($extra_attrs,
            array(
              "src" => $this->thumb_url(),
              "alt" => $this->title,
              "width" => $width,
              "height" => $height)
            );
    // html::image forces an absolute url which we don't want
    return "<img" . html::attributes($attrs) . "/>";
  }

  /**
   * Calculate the largest width/height that fits inside the given maximum, while preserving the
   * aspect ratio.
   * @param int $max Maximum size of the largest dimension
   * @return array
   */
  public function scale_dimensions($max) {
    $width = $this->thumb_width;
    $height = $this->thumb_height;

    if ($height) {
      if (isset($max)) {
        if ($width > $height) {
          $height = (int)($max * ($height / $width));
          $width = $max;
        } else {
          $width = (int)($max * ($width / $height));
          $height = $max;
        }
      }
    } else {
      // Missing thumbnail, can happen on albums with no photos yet.
      // @todo we should enforce a placeholder for those albums.
      $width = 0;
      $height = 0;
    }
    return array($height, $width);
  }

  /**
   * Return an <img> tag for the resize.
   * @param array $extra_attrs  Extra attributes to add to the img tag
   * @return string
   */
  public function resize_img($extra_attrs) {
    $attrs = array_merge($extra_attrs,
            array("src" => $this->resize_url(),
              "alt" => $this->title,
              "width" => $this->resize_width,
              "height" => $this->resize_height)
            );
    // html::image forces an absolute url which we don't want
    return "<img" . html::attributes($attrs) . "/>";
  }

  /**
   * Return a flowplayer <script> tag for movies
   * @param array $extra_attrs
   * @return string
   */
  public function movie_img($extra_attrs) {
    $v = new View("movieplayer.html");
    $v->attrs = array_merge($extra_attrs,
      array("style" => "display:block;width:{$this->width}px;height:{$this->height}px"));
    if (empty($v->attrs["id"])) {
       $v->attrs["id"] = "g-movie-id-{$this->id}";
    }
    return $v;
  }

  /**
   * Return all of the children of this album.  Unless you specify a specific sort order, the
   * results will be ordered by this album's sort order.
   *
   * @chainable
   * @param   integer  SQL limit
   * @param   integer  SQL offset
   * @param   array    additional where clauses
   * @param   array    order_by
   * @return array ORM
   */
  function children($limit=null, $offset=null, $where=array(), $order_by=null) {
    if (empty($order_by)) {
      $order_by = array($this->sort_column => $this->sort_order);
      // Use id as a tie breaker
      if ($this->sort_column != "id") {
        $order_by["id"] = "ASC";
      }
    }
    return parent::children($limit, $offset, $where, $order_by);
  }

  /**
   * Return the children of this album, and all of it's sub-albums.  Unless you specify a specific
   * sort order, the results will be ordered by this album's sort order.  Note that this
   * album's sort order is imposed on all sub-albums, regardless of their sort order.
   *
   * @chainable
   * @param   integer  SQL limit
   * @param   integer  SQL offset
   * @param   array    additional where clauses
   * @return object ORM_Iterator
   */
  function descendants($limit=null, $offset=null, $where=array(), $order_by=null) {
    if (empty($order_by)) {
      $order_by = array($this->sort_column => $this->sort_order);
      // Use id as a tie breaker
      if ($this->sort_column != "id") {
        $order_by["id"] = "ASC";
      }
    }
    return parent::descendants($limit, $offset, $where, $order_by);
  }

  /**
   * Add some custom per-instance rules.
   */
  public function validate($array=null) {
    // validate() is recursive, only modify the rules on the outermost call.
    if (!$array) {
      if ($this->id == 1) {
        // Root album can't have a name or slug
        $this->rules["name"] = array("rules" => array("length[0]"));
        $this->rules["slug"] = array("rules" => array("length[0]"));
      } else {
        // Layer some callbacks on top of the existing rules
        $this->rules["name"]["callbacks"] = array(array($this, "valid_name"));
        $this->rules["slug"]["callbacks"] = array(array($this, "valid_slug"));
      }

      // Movies and photos must have data files
      if (($this->is_photo() || $this->is_movie()) && !$this->loaded()) {
        $this->rules["name"]["callbacks"][] = array($this, "valid_data_file");
      }

      // All items must have a legal parent
      $this->rules["parent_id"]["callbacks"] = array(array($this, "valid_parent"));
    }

    parent::validate($array);
  }

  /**
   * Validate that the desired slug does not conflict.
   */
  public function valid_slug(Validation $v, $field) {
    if (preg_match("/[^A-Za-z0-9-_]/", $this->slug)) {
      $v->add_error("slug", "not_url_safe");
    } else if (db::build()
        ->from("items")
        ->where("parent_id", "=", $this->parent_id)
        ->where("id", "<>", $this->id)
        ->where("slug", "=", $this->slug)
        ->count_records()) {
      $v->add_error("slug", "conflict");
    }
  }

  /**
   * Validate the item name.  It can't conflict with other names, can't contain slashes or
   * trailing periods.
   */
  public function valid_name(Validation $v, $field) {
    if (strpos($this->name, "/") !== false) {
      $v->add_error("name", "no_slashes");
      return;
    }

    if (rtrim($this->name, ".") !== $this->name) {
      $v->add_error("name", "no_trailing_period");
      return;
    }

    if ($this->is_movie() || $this->is_photo()) {
      if ($this->loaded()) {
        // Existing items can't change their extension
        $new_ext = pathinfo($this->name, PATHINFO_EXTENSION);
        $old_ext = pathinfo($this->original()->name, PATHINFO_EXTENSION);
        if (strcasecmp($new_ext, $old_ext)) {
          $v->add_error("name", "illegal_extension");
          return;
        }
      } else {
        // New items must have an extension
        if (!pathinfo($this->name, PATHINFO_EXTENSION)) {
          $v->add_error("name", "illegal_extension");
        }
      }
    }

    if (db::build()
        ->from("items")
        ->where("parent_id", "=", $this->parent_id)
        ->where("id", "<>", $this->id)
        ->where("name", "=", $this->name)
        ->count_records()) {
      $v->add_error("name", "conflict");
    }
  }

  /**
   * Make sure that the data file is well formed (it exists and isn't empty).
   */
  public function valid_data_file(Validation $v, $field) {
    if (!is_file($this->data_file)) {
      $v->add_error("file", "bad_path");
    } else if (filesize($this->data_file) == 0) {
      $v->add_error("file", "empty_file");
    }
  }

  /**
   * Make sure that the parent id refers to an album.
   */
  public function valid_parent(Validation $v, $field) {
    if ($this->id == 1) {
      if ($this->parent_id != 0) {
        $v->add_error("parent_id", "invalid");
      }
    } else {
      if (db::build()
          ->from("items")
          ->where("id", "=", $this->parent_id)
          ->where("type", "=", "album")
          ->count_records() != 1) {
        $v->add_error("parent_id", "invalid");
      }
    }
  }

  /**
   * Make sure that the type is valid.
   */
  static function valid_type($value) {
    return in_array($value, array("album", "photo", "movie"));
  }
}
