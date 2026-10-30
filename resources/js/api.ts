export class ApiError extends Error {
  constructor(message: string, public status: number) {
    super(message);
  }
}
export async function api<T>(path: string, token: string | null, data?: unknown): Promise<T> {
  const response = await fetch(`/api/${path}`, {
    method: data === undefined ? 'GET' : 'POST',
    headers: {
      Accept: 'application/json',
      ...(data === undefined ? {} : {
        'Content-Type': 'application/json'
      }),
      ...(token ? {
        Authorization: `Bearer ${token}`
      } : {})
    },
    body: data === undefined ? undefined : JSON.stringify(data)
  });
  const result = await response.json();
  if (!response.ok) throw new ApiError(Object.values(result.errors ?? {}).flat().join(' ') || ({
    401: 'Sessão inválida. Entre novamente.',
    403: 'Esta ação não está disponível para seu papel.',
    404: 'Sala não encontrada.',
    429: 'Muitas ações seguidas. Aguarde um instante.'
  } as Record<number, string>)[response.status] || 'Não foi possível concluir a ação.', response.status);
  return result;
}
