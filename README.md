A set of custom DDEV commands I use regularly.

I want to use [stow](https://www.gnu.org/software/stow/) to manage these, so I can store just my custom commands in the git repo and they get symlinked to DDEV.

```bash
stow --target=$HOME/.ddev/commands .
```

> [!WARNING]
> Unfortunately that [isn't currently supported](https://github.com/ddev/ddev/issues/5806), so for now I have to use hard links. I've written a script to handle that for me.
>
> The script first deletes all old hard links in `$HOME/.ddev/commands`, then creates new hard links from any files not in the `.links-ignore` list.
> Finally it creates a symlink for the `.php-utils/` directory since that doesn't get copied anywhere.
>
> ```bash
> ./make-links.sh
> ```

## Installation

1. clone the repo somewhere
1. run the above command to make the links
1. run composer install in `.php-utils/`

   ```bash
   cd .php-utils
   composer install
   ```

1. enjoy.

> [!NOTE]
> You may need to run `ddev start` or `ddev debug fix-commands` on any projects that were already running.

## Configuration

You might want/need to add some lil bits to the `global_config.yaml` file for DDEV:

```yaml
custom_commands_config:
    # Path that projects created with `ddev create` will live in
    projects_path: "~/projects"
    # Path that `ddev clone` will clone into
    clone_dir: "~/dump/temp"
    # Token used for github API when fetching PR info
    github_token: "YOUR_TOKEN_HERE"
```
