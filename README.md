# Custom Query Order

A WordPress plugin that extends the core Query Loop block to allow custom drag-and-drop sorting of posts.

## Features

- **Block Variation**: Adds a new "Query Loop (Custom Order)" variation to the block inserter
- **Custom Controls**: Adds a "Custom Order" panel in the block settings sidebar
- **Drag & Drop Interface**: Modal with intuitive drag-and-drop interface to reorder posts
- **Persistent Order**: Saves custom order as block attributes and applies it to the query

## Development

This plugin uses modern WordPress development tools including `@wordpress/scripts` for building.

### Prerequisites

- Node.js and npm installed
- WordPress development environment

### Setup

1. Install dependencies:
```bash
npm install
```

2. Build for production:
```bash
npm run build
```

3. For development with watch mode:
```bash
npm start
```

## Usage

1. Add a "Query Loop (Custom Order)" block to your page
2. Configure the query parameters as needed
3. In the block settings sidebar, open the "Custom Order" panel
4. Click "Manage Post Order" to open the sorting modal
5. Drag and drop posts to reorder them
6. Click "Save Order" to apply the custom order

The custom order will be saved and applied whenever the query is rendered.

## Technical Details

- Uses WordPress block variations to extend the Query Loop block
- Implements custom block controls using `InspectorControls`
- Uses HTML5 drag-and-drop API for sorting
- Modifies the query using the `query_loop_block_query_vars` filter
- Stores order as an array of post IDs in block attributes

## License

GPL-2.0-or-later

