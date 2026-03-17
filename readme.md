# Dominion – 9th Age Campaign System

This project is source-available for personal use only.
All other rights reserved.

A custom campaign management system built around hex-based maps, designed for tabletop campaigns (e.g. The 9th Age).
This project integrates with WordPress and provides a REST-driven backend for managing maps, players, and game state.

---

## 🚀 Features

* 🗺️ Hex map creation and storage
* 👥 Player management with roles:

  * `advanced_player` (map owner / admin)
  * `player` (participant)
* 🔗 Map join / approval system
* ⚙️ Player setup (faction, color, starting city)
* 🔒 Map lifecycle:

  * Created → Ongoing → Ended
* 🌐 REST API for frontend integration
* 🧩 WordPress plugin architecture

---

## 🏗️ Architecture Overview

### Backend (WordPress Plugin)

* Custom Post Type: `hexmap`
* Stores:

  * Map grid data (JSON)
  * Linked players
  * Pending join requests
  * Player setups
* Uses:

  * WordPress REST API
  * Custom fields (ACF or similar)

### Frontend

Loaded via shortcode:

```php
[hexcommand]
```

Assets:

```
/9th_campain/assets/index.js
/9th_campain/assets/index.css
```

---

## 🔌 Installation

1. Copy the plugin into your WordPress installation:

   ```
   wp-content/plugins/hexcommand-maps
   ```

2. Activate the plugin in WordPress admin

3. Ensure required roles exist:

   * `player`
   * `advanced_player`

4. Add the shortcode to a page:

   ```
   [hexcommand]
   ```

---

## 🔐 Roles & Permissions

| Role            | Permissions                                |
| --------------- | ------------------------------------------ |
| advanced_player | Create, edit, delete maps, approve players |
| player          | Join maps, configure setup                 |
| guest           | No access                                  |

---

## 📡 REST API

Base route:

```
/wp-json/hexcommand/v1
```

### Core Endpoints

#### 👤 User

* `GET /me`
  Get current user info and role

#### 🗺️ Maps

* `GET /maps`
  List maps linked to the user

* `POST /maps` *(advanced_player only)*
  Create a new map

* `GET /maps/{uid}`
  Load a map

* `DELETE /maps/{uid}` *(owner only)*
  Delete a map

#### 🤝 Participation

* `POST /maps/{uid}/join`
  Request to join a map

* `GET /requests` *(advanced_player only)*
  List pending join requests

* `POST /maps/{uid}/approve/{user_id}`
  Approve a player

* `POST /maps/{uid}/deny/{user_id}`
  Deny a player

#### ⚙️ Game Setup

* `POST /maps/{uid}/setup`
  Save player configuration

#### 🎮 Game Lifecycle

* `POST /maps/{uid}/finish`
  Start the game

* `POST /maps/{uid}/end`
  End the game

---

## 🧠 Data Model

### Map (hexmap post type)

* `hexmap_uid` → Unique identifier
* `hexmap_state` → created | ongoing | ended
* `hexmap_data` → JSON hex grid
* `_hexmap_cols`, `_hexmap_rows`, `_hexmap_size`
* `users_linked` → Approved players
* `pending_requests` → Waiting players
* `player_setups` → Player configurations

---

## ⚠️ License

This project is **NOT open source**.

* Private use is allowed
* Modification, distribution, and commercial use are strictly prohibited
* See the `LICENSE` file for full terms

---

## 📌 Notes

* Designed for controlled environments (private campaigns)
* Not intended for public distribution or SaaS deployment
* Requires a frontend client to fully operate

---

## 📬 Contact

For permissions or questions, contact:

* GitHub: https://github.com/Loricen

---
