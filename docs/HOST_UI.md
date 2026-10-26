# Interface do site que hospeda o Track

O site pode criar um arquivo PHP **confiável, versionado e fixo** em \`utm-ui.php\`, ao lado da pasta que recebeu a cópia do Track. A carga é feita por \`lib/host.php\`; nenhum caminho vem da requisição. Sem esse arquivo, o Track mantém sua interface atual.

Pontos opcionais:

- \`track_host_name(): string\`: nome no título.
- \`track_host_head(): void\`: estilos e módulos locais depois do CSS do núcleo.
- \`track_host_body(): string\`: atributos do body, produzidos pelo código confiável do site.
- \`track_host_sidebar(string $current, string $base, ?string $cms, string $tools): string\`: navegação, mantendo as permissões do núcleo.
- \`track_host_theme_attributes(): string\` e \`track_host_theme_form(string $returnTo, string $base): string\`: aparência.
- \`track_host_login(array $config, string $user, string $returnTo, string $error): void\`: **apenas a apresentação**, depois de processar toda a autenticação existente.
- \`track_host_integrations(string $tab): void\`: catálogo, depois da verificação do acesso a Integrações.
- \`track_host_data_directory(): string\`: diretório de dados do site. A variável explícita \`TRACK_DADOS\` continua tendo prioridade.

O host não deve remover CSRF, permissões, escape de saída ou conferência de retorno do login. Deve manter dados e chaves fora da pasta pública e usar a fonte local permitida pela CSP. O novo ID \`track-workspace\` no conteúdo permite um link de pular para o conteúdo.

As alterações genéricas são feitas aqui; copie o núcleo pelo script existente. A UI específica fica no site e sobrevive à próxima cópia.
